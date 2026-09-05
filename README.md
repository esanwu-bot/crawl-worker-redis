# crawl-worker-redis（PHP CLI 采集 Worker · Redis Stream 版）

> 作品集中的一个「真实站点采集」子项目：以常驻 PHP 进程 + Redis Stream 消费组实现
> 一个可**断点续采 / 崩溃恢复 / 失败重试 / 死信隔离**的元器件型号采集器。
> 数据源为真实的电子元器件站 shikues.com 的产品目录接口（即站点页面自身异步加载的数据源），
> 抓取其「系列 → 型号(行)」两层结构，清洗后幂等写入 MySQL。

---

## 一、解决什么问题 / 设计动机

普通 MySQL 版任务表方案在**高并发分片、断点续采、重复投递**上需要自己实现很多细节
（锁表、游标、扫描轮询……）。Redis Stream 天然提供：

| 要解决的问题 | Redis 方案 | 代码体现 |
| --- | --- | --- |
| 任务排队与公平消费 | `XADD` 追加 + 消费组 `XREADGROUP` | `RedisStore::addTask / readBatch` |
| 横向扩容（多 Worker） | 同组多个 consumer，消息不重复投递 | 开多个 `bin/worker.php --name wX` |
| 崩溃恢复 / 断点续采 | 未 ACK 消息留在 PEL，超时后接管重投 | `XPENDING IDLE + XCLAIM` 接管 |
| 失败重试 | 失败不 ACK → 按 `claim_idle` 周期自动重试 | `Worker::onFail` |
| 垃圾/毒丸任务 | 超 max_attempts 转死信流 | `tasks:dead` + `bin/stats.php` 可查 |
| 每系列进度 | 游标 Hash（done_pages/total_pages/status） | `cur:{type_id}` |
| 结果幂等 | MySQL `(source, model)` 唯一键 upsert | `Db::upsertModels` |

**分层（与 MySQL 版 crawl-worker 对齐）**

```
┌───────────── 任务层(Redis) ─────────────┐   ┌────────── 结果层(MySQL) ──────────┐
│ Stream:  tasks  = 待抓页码任务           │   │ source_types  系列元数据           │
│         tasks:dead = 死信               │   │ product_models 型号行(source+model)│
│ Hash:  cur:{type_id} = 每系列游标/进度   │   └──────────────────────────────────┘
│        stats = 全局计数                  │
│        attempt:{msgId} = 消息重试次数    │
└─────────────────────────────────────────┘
```

---

## 二、目录结构

```
crawl-worker-redis/
├─ bin/
│  ├─ init_db.php   初始化 MySQL 库表（幂等）
│  ├─ seed.php      播种：发现系列 → 写元数据 → 投首页任务
│  ├─ worker.php    常驻消费 Worker（可多开）
│  ├─ stats.php     任务层+结果层运行总览
│  └─ reset.php     清空 Redis 任务层（演示重跑用，不影响 MySQL）
├─ config/config.php  统一配置（MySQL/Redis/Http/范围，支持环境变量覆盖）
├─ sql/schema.sql     MySQL 表结构
├─ src/
│  ├─ bootstrap.php   autoload + CLI 参数解析
│  ├─ ApiClient.php   shikues 站点接口客户端
│  ├─ Normalizer.php  行数据清洗 → 落库模型（封装/参数 JSON 异构兼容）
│  ├─ RedisStore.php  Stream/消费组/游标/接管/统计封装
│  ├─ Producer.php    播种逻辑
│  ├─ Worker.php      消费主循环与失败语义
│  ├─ Http.php / Db.php / Logger.php
├─ samples/env_test.php  环境连通性自检
├─ tests/smoke.php       冒烟/回归测试（离线，A/B/C 或全量）
└─ logs/               运行日志（worker.log / seed.log）
```

---

## 三、环境要求

- PHP 8.0+（CLI），扩展：`redis`、`curl`、`pdo_mysql`、`dom`
- Redis 6.2+（本次演示使用远程 Redis 6.2；因为接管机制用到 `XPENDING ... IDLE` 过滤）
- MySQL 5.7+（演示使用本机 5.7，账号密码默认 `root/root`）
- 本机可访问 shikues 站点接口（需联网）

> 环境自检：`php samples/env_test.php`（会测 Redis ping/XADD 与 MySQL 连通）。

---

## 四、快速开始（真实采集演示）

```bash
# 1) 初始化 MySQL（自动建库建表）
php bin/init_db.php

# 2) 播种：发现"分立元器件"大类的系列，并把每个系列的首页任务投到 Stream
php bin/seed.php --types 1,3,4 --max-pages 3
#    --types 1,3,4   指定系列（不传则自动取 id 升序前 3 个）
#    --max-pages 3   每系列最多抓 3 页（演示限速）

# 3) 启动常驻 Worker（可开多个终端模拟横向扩容）
php bin/worker.php --name w1
php bin/worker.php --name w2
#    --idle-rounds N  连续空转 N 轮后自动退出（默认按 config；0=永不退出）

# 4) 查看运行状态与 MySQL 落库结果
php bin/stats.php --recent 5
```

演示结果样例（本机实跑）：

```
[2026-09-05 11:37:04] INFO [1] p1/3 Low VF肖特基二极管(CLAIM) 写入 15 行，游标 page=1 total=3 rows=15
[2026-09-05 11:37:14] INFO [1] p2/3 Low VF肖特基二极管(NEW)  写入 15 行，游标 page=2 total=3 rows=30
[最终状态] dead_letters=0 page_done=27 pending=0 rows_upsert=376 stream_len=27
[MySQL] source_types=12 product_models=419
```

---

## 五、可靠性演示脚本（评审用）

**A. 崩溃恢复（XCLAIM 接管语义）** —— 模拟 Worker 处理中被 kill：

```bash
# 开一个大一点的采集面，保证处理没结束就杀掉
php bin/reset.php
php bin/seed.php --types 1,3,4,6,8,9,10,11,12,13 --max-pages 3

# PowerShell：启动后台 worker，读走一批任务后强杀（模拟宕机）
$p = Start-Process php -ArgumentList 'bin/worker.php','--name','wb','--idle-rounds','0' -PassThru
Start-Sleep -Milliseconds 4000
Stop-Process -Id $p.Id -Force

# 查看遗留：这些消息已投递给 wb 但未 ACK，停留在 PEL
php bin/stats.php    # 可见 PEL 未确认 > 0

# 重启新 consumer：日志中会出现带 (CLAIM) 标签的任务 = 自动接管了崩溃遗留消息
php bin/worker.php --name w2 --idle-rounds 0
php bin/stats.php    # PEL 归 0，MySQL 数据不重不漏
```

实跑证据（2026-09-05 本机）：worker `wb` 处理中被强杀，`stats.php` 显示
`PEL 未确认: 3`；随后新 consumer `w2` 启动，日志出现 3 条 `(CLAIM)`：

```
[2026-09-05 12:19:28] INFO [4] p1/12 普通整流二极管(CLAIM) 写入 15 行，游标 page=1 total=12 rows=15
[2026-09-05 12:19:29] INFO [6] p1/10 快恢复整流管(CLAIM) 写入 15 行，游标 page=1 total=10 rows=15
[2026-09-05 12:19:31] INFO [8] p1/10 超快恢复整流管(CLAIM) 写入 15 行，游标 page=1 total=10 rows=15
```

最终状态：`PEL 未确认: 0`，`dead_letters=0`，`page_done=25`、`rows_upsert=346`，
MySQL `product_models` 不重不漏（断点续采 + 唯一键 upsert）。

> 实现要点：接管不依赖 `XAUTOCLAIM` 命令（部分云 Redis 网关/低版本不支持），
> 用等价的 `XPENDING stream group IDLE <ms> - + N` 定位 + `XCLAIM` 转移实现，
> 兼容性更好，且原生命令均为 Redis 5.0 基础能力。
>
> 演示小技巧：接管判定的失联阈值 `claim_idle` 默认 30s，若想快速看到接管，
> 可在启动前设 `CW_CLAIM_IDLE_MS=3000` 缩短到 3s（本次实跑即使用 3s）。

**B. 失败重试与死信** —— 临时断网/接口 5xx 时：失败消息不 ACK 留在 PEL，
每 `claim_idle`(默认 30s) 自动重试，超过 `max_attempts`(3) 转入 `tasks:dead`，
`bin/stats.php` 中 `dead_letters` 可观测，事后可分析死信流恢复。

**C. 幂等重采** —— 重跑不会重复入库：

```bash
php bin/seed.php --types 1,3,4 --max-pages 3 --force   # 重置游标重采
php bin/worker.php --name w1 --idle-rounds 5
php bin/stats.php   # product_models 数量不变（source+model 唯一键 upsert）
```

---

## 七、配置与生产化建议

- 所有环境相关项集中 `config/config.php`，支持环境变量覆盖
  （`CW_MYSQL_*` / `CW_REDIS_HOST` / `CW_REDIS_PORT` / `CW_REDIS_AUTH` /
  `CW_SOURCE` / `CW_MAX_PAGES` / `CW_CA_BUNDLE` 等）。
- ⚠️ 仓库配置默认指向本机 `127.0.0.1:6379`（无密码），**不存放任何真实 Redis 连接凭据**；
  远程 Redis 的 host/auth 通过 `CW_REDIS_HOST` / `CW_REDIS_AUTH` 环境变量注入。
- 生产 HTTPS 证书：设置 `CW_CA_BUNDLE`；本机 phpstudy 等缺系统 CA 时会按
  `insecure_fallback` 降级（仅限本地/演示）。
- 常驻部署：`php bin/worker.php --idle-rounds 0`，用 Supervisor / NSSM /
  Windows 计划任务守护，进程数 = 消费能力，靠消费组天然负载均衡。

---

## 八、本机运行备注（Windows）

- 项目已迁至无中文目录 `E:\workspace\phpworkspace\crawl-worker-redis`，
  可直接在 cmd/PowerShell 运行，不再需要 junction：

  ```powershell
  cd E:\workspace\phpworkspace\crawl-worker-redis
  php bin/seed.php --types 1,3,4
  ```

---

## 九、冒烟/回归测试

项目自带离线冒烟测试，**不依赖站点网络**，用于面试/评审前一键自检关键语义：

```bash
php tests/smoke.php            # 全量：环境 + Redis 任务层 + MySQL 清洗/幂等 + Worker 全链路
php tests/smoke.php --no-worker  # 只跑 A/B/C（<1s），跳过 Worker 场景
```

实跑结果（2026-09-05 本机）：`49/49 通过`，其中 D 节失败链路日志片段：

```
[WARN] Fail Series(1) 失败(第 1 次)，留在 PEL，约 1ms 后接管重试: smoke: 模拟网络故障
[WARN] Fail Series(1) 失败(第 2 次)，留在 PEL，约 1ms 后接管重试: smoke: 模拟网络故障
[ERROR] Fail Series(1) 重试 3/3 仍失败，转入死信: smoke: 模拟网络故障
[最终状态] dead_letters=1 page_fail=3 pending=0 ...
```

> `exit code`：全部通过为 0，有失败为 1，执行异常为 2，便于接入 CI。
