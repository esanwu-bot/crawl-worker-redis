# 代理 IP 池方案（crawl-worker-redis）

> 目标：给采集链路加代理能力，突破/规避目标站（shikues.com）的单 IP 频率风控，
> 同时**不破坏现有任务语义**（重试 / 死信 / 游标 / 幂等）。

---

## 一、为什么需要：现状与需求信号

- 全链路只有一个出口：`ApiClient → Http::fetch(curl)`，本机真实出口 IP 只有一个。
- 单 IP 高频请求会触发站点反爬。项目里已经有一处"信号"——Http 对返回 HTML 的风控页做了检测：

```88:91:src/Http.php
        // 站点公共接口偶发返回 HTML（反爬/风控页），做一次形状校验
        if (isset($body[0]) && $body[0] === '<') {
            throw new RuntimeException('返回内容为 HTML（疑似被风控拦截）');
        }
```

- 多开 Worker（横向扩容）只会让**同一个出口 IP 的并发更高**，风控概率更大。
  代理池的本质 = 把"单出口高频"摊到"多出口各低频"，并在出口被封时自动换下一个。

---

## 二、三种代理形态（选型）

| 形态 | 说明 | 适用 | 集成成本 |
| --- | --- | --- | --- |
| 静态代理 | 一个固定 `ip:port`，不轮换 | 少量慢速采集、验证链路 | 极低（1 个 curl 参数） |
| 隧道代理 | 服务商给一个域名:端口，出口 IP 由服务商自动轮换 | 演示 / 不想自己维护池 | 低（仍是一个 curl 参数） |
| **代理 IP 池** | 自维护一批 IP：进货 → 验证 → 分配 → 冷却/淘汰 | 真实大规模稳定采 | 中（本方案主体） |

本方案实现 **第三种（池）**，但兼容第一种：池内只有一个 IP 时就等价于静态代理。
同时保留**直连开关**——`proxy.enabled=false`（默认）时整条链路与现在完全一致。

---

## 三、总体架构

```text
                    ┌────────────────────────────┐
                    │    代理供应商（外部）        │
                    │  提取接口 / 隧道 / 静态地址   │
                    └─────────────┬──────────────┘
                                  │ list 注入（config / 环境变量）
                    ┌─────────────▼──────────────┐
                    │   ProxyPool（src/ProxyPool.php）│
                    │   round-robin 分配           │
                    │   fail/ok 健康标记            │
                    │   冷却 cooldown / 连续失败弃用 │
                    └─────────────┬──────────────┘
                                  │ next() 给本次请求一个代理
                    ┌─────────────▼──────────────┐
                    │    Http::fetch(curl)        │
                    │    CURLOPT_PROXY            │
                    │    CURLOPT_PROXYUSERPWD     │
                    └─────────────┬──────────────┘
                                  │ 转发请求
                    ┌─────────────▼──────────────┐
                    │   目标站 api.shikues.com     │
                    └────────────────────────────┘
```

分层原则：
1. **代理只在 `Http` 层生效**，`ApiClient / Worker / Producer / 任务层` 一行不改；
2. **代理层的失败不能污染任务语义**——见下一节的"失败归因"。

---

## 四、失败归因（本方案最关键的设计）

现有语义：`Http` 抛 `RuntimeException` → `Worker::onFail` → 尝试上限后进 `tasks:dead`。
如果"代理不通"也算任务失败，好任务会因代理挂掉被误判成死信。因此拆三类：

| 类型 | 典型表现 | 归因 | 处置 |
| --- | --- | --- | --- |
| **A 代理不可用** | curl connect 失败、407 认证错、隧道超时、HTTP 0 | `ProxyException`（新增，A 类内部消化） | `markBad(冷却)`，本次请求**内部换下一个代理重试**，不动任务 attempt |
| **B 目标站风控** | HTTP 403、返回 HTML 风控页 | 普通 `RuntimeException` | `markBad(该出口嫌疑冷却)`，抛给 Worker 走任务重试——重试时换到新 IP 才有意义 |
| **C 目标/业务错误** | 5xx、JSON 解析失败、404 等 | 普通 `RuntimeException` | 维持现状：任务正常重试 → 超限进死信 |

---

## 五、实现改动清单（本仓库已落地"模拟版"）

| 文件 | 改动 |
| --- | --- |
| `config/config.php` | `http` 段新增 `proxy` 子配置（含开关，支持环境变量覆盖） |
| `src/ProxyPool.php` | **新增**：池组件（分配 / 健康标记 / 冷却 / 弃用 / 统计） |
| `src/Http.php` | **唯一出口改造**：构造时按配置创建池；`fetch()` 挂 `CURLOPT_PROXY`；`getJson()` 按失败归因换代理重试 |

### 5.1 配置（config/config.php → http.proxy）

```php
'proxy' => [
    'enabled'         => $env('CW_PROXY_ENABLED', '0') === '1', // 默认关闭，完全旁路
    'mode'            => $env('CW_PROXY_MODE', 'pool'),         // static=固定第一项 / pool=轮换
    'list'            => ..., // 代理列表，如 'user:pass@1.2.3.4:8080,5.6.7.8:3128'
    'retries'         => 2,             // A 类失败最多连续换几个代理
    'cooldown_ms'     => 30000,         // 坏代理冷却时长
    'drop_after_fails'=> 3,             // 连续失败达此值 → 弃用该代理
],
```

### 5.2 ProxyPool（src/ProxyPool.php）

- `next(): ?array`：round-robin 分配；跳过冷却中/已弃用项；支持 `mode=static` 时固定第一项。
- `markBad(host, reason)`：失败计数 +1，进入冷却（时长随连续失败递增）；连续失败达
  `drop_after_fails` 弃用；返回当前失败次数。
- `markOk(host)`：成功后清零失败计数。
- `stats()` / `size()` / `countAvailable()`：池状态，供演示/日志观测。

### 5.3 Http 层（src/Http.php）

- 构造时 `$cfg['proxy']['enabled']` 为真则创建 `ProxyPool`，否则 `$pool = null`（纯直连）。
- `fetch()` 改造要点：

```php
if ($proxy !== null) {
    curl_setopt($ch, CURLOPT_PROXY, $proxy['host']);
    if ($proxy['auth'] !== '') {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['auth']);
    }
}
```

- `fetch()` 错误归因：
  - 连接层失败 / HTTP 0 / 407 → 记 `ProxyException`（A 类）并 `markBad`；
  - HTTP 403 或返回 HTML 风控页 → `markBad`（B 类嫌疑）后抛普通 `RuntimeException`；
  - 其它 4xx/5xx / JSON 解析失败 → 普通 `RuntimeException`（C 类，不标记代理）。
- `getJson()` 重试循环：

```php
$proxySwapLeft = $this->pool ? (int)($this->cfg['proxy']['retries'] ?? 2) : 0;
for ($attempt = 0; $attempt <= $maxRetry + $proxySwapLeft; $attempt++) {
    $proxy = $this->pool?->next();          // null = 直连
    try {
        $body  = $this->fetch($url, $proxy);
        ...json 校验...
        return $json;                        // 成功
    } catch (ProxyException $e) {            // A：代理挂了 → 换代理再来
        if ($proxySwapLeft > 0) { $proxySwapLeft--; continue; }
        $lastErr = $e->getMessage();
    } catch (RuntimeException $e) {          // B/C：目标站/业务错误
        $lastErr = $e->getMessage();
    }
}
throw new RuntimeException("GET {$url} 失败: {$lastErr}");
```

`ApiClient` 与上游完全无需改动，天然获得代理能力。

---

## 六、与现有可靠性的配合

| 现有机制 | 与代理池的关系 |
| --- | --- |
| `attempt:{msgId}` / `max_attempts` | A 类代理失败**不 bump**（内部消化）；只有 B/C 类进入任务重试，不会因代理抖动误进死信 |
| 死信 `tasks:dead` | 若某页连续 3 次都撞上 B 类风控，仍进死信——符合预期，证明风控而非逻辑故障 |
| `Http` 已检测 HTML 风控页 | 升级为：命中即 `markBad` 出口，换 IP 后再战，提升成功率 |
| 多 Worker 横向扩容 | 池内 IP 数应 ≥ Worker 并发数，否则退化为同 IP 高频 |
| 幂等/游标 | 与 IP 无关，天然兼容，换 IP 重采不重不漏 |

---

## 七、落地路线

- **Phase 1 · 静态/隧道（半天）**：`proxy.list` 填一个隧道地址 → `mode=static` → 收工。
  适合"评审演示能突破风控"。
- **Phase 2 · 自建池（本仓库已给模拟版，约 2~3 天接真实供应商）**：
  对接供应商"提取接口"批量进货、过期 IP 清理（类比游标 `cur:*` 的平滑思路）、
  落地 Redis 化以便多 Worker 共享与重启恢复。
- **Phase 3 · 智能调度（可选加分）**：IP 评分（成功率/延迟）、按域名的冷却策略、
  代理池指标并入 `stats.php`/日志，可讲"风控后自动换池"的完整闭环。

---

## 八、模拟版边界（当前代码状态）

当前仓库落地的是**可运行的模拟/骨架实现**，边界如下：

- ✅ 池的分配 / 冷却 / 弃用 / 统计逻辑真实可用；
- ✅ 默认 `enabled=false`，直连行为与改动前完全一致，零风险；
- ⚠️ 未接任何真实代理供应商，`list` 需自行填充真实可用代理后 `enabled=true` 才真正走代理；
- ⚠️ 池状态存于内存（进程内），未做 Redis 化持久化 / 多进程共享（Phase 2 的升级点）；
- ⚠️ 未做"进货 verifier"后台任务（Phase 2/3 的升级点）。

### 快速验证

```bash
# 1) 语法自检
php -l src/ProxyPool.php && php -l src/Http.php && php -l config/config.php

# 2) 直连回归（默认关闭，行为与改造前一致）
php samples/env_test.php

# 3) 开代理跑（需先把 list 换成真实可用代理）
set CW_PROXY_ENABLED=1
set CW_PROXY_LIST=127.0.0.1:7890    # 示例；Windows PowerShell 用 $env: 前缀
php bin/seed.php --types 1,3,4 --max-pages 3
php bin/worker.php --name w1 --idle-rounds 5
```
