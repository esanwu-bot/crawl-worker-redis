# Handoff — 主仓库运行链路打通：MySQL 重建 + 远程 Redis 迁移（2026-09-09）

> 记录时间：2026-09-09
> 范围：根目录 `crawl-worker-redis` 主工程（Task-Stream-Adapter 模型）。`agentVersion/` 子工程交接见该目录下独立 `handoff.md`。
> 起因：本机 phpstudy 的 MySQL/Redis 环境异常，本次完成环境定位、数据库重建与 `seed → Stream → worker → MySQL` 全链路打通。

---

## 1. 现状一句话

主仓库已跑通全链路，且**命令不再需要手工注入环境变量**：远程连接凭据固化在根目录 `.env.local`（已 gitignore）。当前链路：MySQL 本机 5.7.26:3306（重建库）+ Redis 远程 6.2.22（腾讯云）。

## 2. 环境结论（关键事实，避免再踩坑）

| 组件 | 结论 |
|---|---|
| MySQL | 本机装了两套 phpstudy：`D:\phpstudy_pro`（**运行中**，5.7.26 监听 **3306**，root/root 可连）与 `D:\Program Files (x86)\phpstudy`（MySQL **8.0.12**，未运行）。当前所有脚本实际连的就是 3306 上的 5.7.26。 |
| Redis | 本机 Redis **3.0.504 不支持 Stream** —— 这是此前 `XADD` 报错（`ERR unknown command`）的根源。已切换远程 `8.152.97.191:6379`（带 auth）＝ Redis 6.2.22，Stream / 消费组全能力验证通过。 |

## 3. 本次改动清单

| 文件 | 类型 | 说明 |
|---|---|---|
| `config/config.php` | 修改 | 顶部新增 `.env.local` 加载器：解析 `KEY=VALUE`（忽略 `#` 注释/空行、可去成对引号），真实 shell 环境变量优先、本地文件兜底，写回 `putenv`/`$_ENV`/`$_SERVER`；mysql 段注释同步更新 |
| `.env.local` | 新增（不入库） | `CW_REDIS_HOST=8.152.97.191` / `CW_REDIS_PORT=6379` / `CW_REDIS_AUTH=…`；MySQL 项以注释预留 |
| `.gitignore` | 修改 | 追加 `.env.local`、`.env.*.local` |
| `src/Db.php` | 修改 | MySQL 连接端口容错：`alt_ports` 附加 + 兜底 3306/3307/3308 逐个探测，适配本机双实例并存；实际连通端口回写 cfg |
| `sql/schema.sql` | 重导 | `DROP DATABASE` + `CREATE DATABASE shikues_crawler`（utf8mb4）后重新导入，4 张业务表建齐 |
| `src/Contract/Task.php` / `src/Producer.php` / `src/Worker.php` | 修改 | 对齐任务载荷协议（见下节） |

## 4. 协议不一致修复（本次排障核心）

现象：seed 已投递（Stream len=2），worker 却读不到，消息最终全部进死信；死信内容为「非法任务载荷（缺少 source/entity/unit/page）」。

根因：seed 侧投递载荷结构与 worker 侧 `Task::isValidList` 校验不一致（老消息把 unit 拍平在顶层，契约要求 `cursor` 嵌套）。

结论（当前契约，见 `src/Contract/Task.php`）：
- 消息顶层：`source` / `entity` / `operation` / `cursor{unit_id, unit_name, page}`，可选 `params` / `target_pages` / `job_id`
- Producer 播种一律 `Task::home()` 生成，Worker 消费一律 `Task::isValidList()` 校验（`page>0`），cursor 定位用 `Task::cursorKey()`
- 校验失败的载荷不阻塞主流程，直接进死信流并记录原因（便于此类对账）

## 5. 验证结果（2026-09-09 实测，全程未手工注入 env）

```text
# seed（--force 重采首页任务）
[shikues|Low VF肖特基二极管|p1] 已投递首页任务 1788963780207-0（max_pages=1）

# worker 消费（--idle-rounds 5）
[shikues|Low VF肖特基二极管|p1] p1/3 NEW 写入 15 行，游标 page=1 total=3 rows=15 [END]
[最终状态] dead_letters=0 page_done=2 pending=0 rows_upsert=30 seeded_units=2 stream_len=2
[MySQL] crawl_jobs=6 crawl_records=15（legacy product_models=0）
```

- 死信清零、pending 清零；`crawl_records=15` 证明重复重采/重复消费**幂等**（upsert 不重复入库）
- `crawl_jobs=6` 是多次 `--force` 播种累积的作业记录，属预期

## 6. 日常运行（凭据自动来自 `.env.local`）

```bash
php bin/seed.php --limit=3 --max-pages=3    # 播种（默认跳过已完成单元，--force 重采）
php bin/worker.php --idle-rounds 0          # 常驻消费，可多开（同一消费组负载均衡）
```

## 7. 注意事项 / 待办

- `.env.local` 已入 `.gitignore`，密码变更只改本地即可，不会进版本库。
- 若要真正切 MySQL 8：启动 `D:\Program Files (x86)\phpstudy` 那套 8.0.12 并绑定监听端口，然后在 `.env.local` 放开 `CW_MYSQL_PORT`（Db 会自动在 3306/3307/3308 探测）。
- 远程 Redis 上残留此前测试产生的死信消息（`tasks:dead`），如需清零可手动删除相关 key，或后续加一键清理命令。
- 注意本机存在两套 phpstudy，操作服务前先 `Get-Process mysqld` / 看 `my.ini` 确认实例归属，避免改错套件。

---

# 附：Agent Workbench Phase 1 增量（2026-09-10）

> 范围：`agentVersion/`（子工程独立 Composer 仓库）；复用其已有的 `bin/orchestrator.php serve` + `bin/orchestrator-router.php` HTTP 入口与 `/api/jobs` `/api/stats` `/api/dead` 等路由。
> 目标：把方案 §5「启动第一步」落到可点开看、可下发任务的最小 Workbench 控制台（`/workbench.html`），并把原型 `demo.html` 旁路展示在 `/demo.html`。

## A. 新增 / 修改

| 文件 | 类型 | 说明 |
|---|---|---|
| `agentVersion/src/RedisStore.php` | 修改 | 新增 `heartbeat()` / `clearHeartbeat()` / `workersOverview()` / `cursorsOverview()`；`workersOverview/cursorsOverview` 修复「双 prefix」bug（hGetAll 不再用 SCAN 返回的已带 prefix key） |
| `agentVersion/src/Worker.php` | 修改 | `start()` 每轮 `$this->round++` 后调 `store->heartbeat()`（TTL 30s）；退出时 `clearHeartbeat()` 主动清，避免依赖 TTL |
| `agentVersion/bin/orchestrator-router.php` | 修改 | 新增 `GET /api/workers` `GET /api/progress`；`/api/stats` 聚合 cursors+workers；`PlannerFactory/Workflow` 懒加载（避免 `PlannerFactory::create` 的 INFO 日志污染其它接口响应）；`json()` 加 `ob_end_clean()` 兜底 |
| `agentVersion/ui/workbench.html` | 新增 | 轻量 SPA：4 卡片（今日入库/Stream/Pending/死信）+ 单元进度表 + Worker 心跳表 + 死信列表 + 自然语言 chat（`POST /api/jobs`） |
| `agentVersion/ui/index.html` | 修改 | 入口页：两个卡片链接到 `/workbench.html`（live）和 `/demo.html`（静态设计稿），并附 API 列表 |
| `agentVersion/ui/demo.html` | 新增 | 由 `agentVersion/prototype/demo.html` 复制，让内置服务器可直接预览设计稿 |

## B. 启动方式

```bash
# 1) 启动 Workbench HTTP 服务（前台或后台均可）
cd agentVersion
php bin/orchestrator.php serve --host 127.0.0.1 --port 8787

# 2) 起常驻 Worker 集群（每个进程自动写心跳，前端可看到 alive=1/N）
php bin/worker.php --idle-rounds 0   # 0 = 永不退出
```

浏览器打开 `http://127.0.0.1:8787/`，可进：
- `/workbench.html`：实时仪表盘（每 3s 调 `/api/stats`）
- `/demo.html`：原型设计稿

## C. 接口清单

| 路由 | 方法 | 作用 |
|---|---|---|
| `/api/health` | GET | 健康检查（PHP/Planner 类型） |
| `/api/stats`   | GET | 引擎指标 + cursors + workers 聚合 |
| `/api/workers` | GET | Worker 心跳列表（含 alive / age_seconds / round） |
| `/api/progress`| GET | 所有 `cur:*` 游标（type_id/done/total/rows/progress） |
| `/api/dead`    | GET | 最近死信载荷 |
| `/api/jobs`    | GET / POST | 列任务 / 创建（POST 接受 `intent/approve/id`） |
| `/api/jobs/{id}` | GET | 任务详情 + runs + stats |
| `/api/jobs/{id}/approve` | POST | 人工审批通过后继续 |
| `/api/jobs/{id}/control` | POST | pause / resume / cancel |

## D. 验证（2026-09-10 实测）

```text
GET /api/health   → {"ok":true,"php":"8.2.9"}
GET /api/stats    → {"pending":0,"stream_len":0,"dead_letters":0,"source_types":12,
                     "product_models":419,"cursors":[...],"workers":[...]}
常驻 worker 启动后 GET /api/workers → 1 条 alive=true, age_seconds=0, round=持续增长
GET /api/jobs     → 历史 5 条 pending_approval 任务（planner=llm/deepseek-v3.2、metadata.tool_runs 等）
GET /workbench.html → HTTP 200 9571B
GET /demo.html      → HTTP 200 45045B
```

## E. 仍未做（后续 phase）

- **Phase 1 收尾**：WebSocket 推送、进度条组件化、原型 demo 接入真实 API（当前 demo.html 是纯静态）
- **Phase 2**：API 嗅探工具（`analyze_site` / `sniff_api`）、LLM 生成 DDL + Adapter、热加载 Mapping
- **Phase 3**：HTTP 429 熔断 + Decision Log、代理池联动（`switch_proxy_strategy`）、WebSocket 大屏
- **Phase 4**：多租户（`tenant_A:tasks` prefix 隔离）、Schema 可视化编辑、CSV/Excel 导出

详细 Roadmap 见 `agentVersion/handoff.md` §8。

