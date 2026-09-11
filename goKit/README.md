# Crawler Engine (Go)

> 把现有 PHP `crawl-worker-redis` 的 **Redis Stream 采集 Runtime**，按 Go 工程化方式重构为一个可扩展的
> **采集引擎（Crawler Engine）**，并为后续「采集 Agent / Orchestrator / Workbench」提供 Runtime 基础设施。

这不是一次「PHP 逐文件翻译」，而是**保留业务语义与 Redis Stream 模型，重新设计分层与边界**。

```
API → Orchestrator → Redis Stream → Worker → Adapter → Normalizer → Storage
```

---

## 1. 设计原则

| 原则 | 说明 |
| --- | --- |
| **Runtime 不感知数据源** | Worker / Producer / RedisStore 只依赖 `task.Payload` 与 `source.Adapter` 契约；新增数据源不改 Runtime |
| **契约先行** | `domain` 层定义 `Canonical Record` / `Task Payload` / `Cursor` / `Job`，Adapter 只做「连接 + 抽取 + 归一化」 |
| **保留 Redis Stream 语义** | `XADD / XREADGROUP / XPENDING / XCLAIM / XACK` + 消费组 + PEL 接管 + 死信，横向扩展方式与 PHP 版一致 |
| **失败分级** | 区分「可重试」（保留 PEL 待接管）与「终态失败」（转死信），并带错误分类 |
| **可观测** | 统一日志 + Redis 统计 + `/api/v1/metrics` |
| **幂等** | 结果层以 `(source, entity, unit_id, external_id)` 唯一键 upsert |

---

## 2. 架构

```
                    ┌─────────────────────────────┐
                    │   crawler-api (REST/Agent)  │
                    └──────────────┬──────────────┘
                                   │
                    ┌──────────────▼──────────────┐
                    │  Orchestrator / Producer    │
                    │  发现单元 → 建 Job → 投递任务 │
                    └──────────────┬──────────────┘
                                   │
                          Redis Stream (消费组)
                                   │
        ┌──────────────────────────┼──────────────────────────┐
        ▼                          ▼                          ▼
  crawler-worker#1          crawler-worker#2           crawler-worker#N
        │                          │                          │
        └──────────────────────────┼──────────────────────────┘
                                   ▼
                        ┌─────────────────────┐
                        │   Source Adapter    │
                        ├─────────────────────┤
                        │ shikues │ maccms │… │
                        └──────────┬──────────┘
                                   │
                          HTTP / Proxy / TLS
                                   │
                                   ▼
                        ┌─────────────────────┐
                        │    Normalizer       │
                        │ → Canonical Record  │
                        └──────────┬──────────┘
                                   ▼
                        ┌─────────────────────┐
                        │  Result Writer      │
                        └──────────┬──────────┘
                                   ▼
                                MySQL
```

**边界一句话：Agent 决策、Orchestrator 编排、Worker 执行。**

---

## 3. 目录结构

```
goKit/
├── cmd/
│   ├── crawler-api/         # REST/Admin/Agent API
│   ├── crawler-worker/      # Stream 消费者（可多实例）
│   ├── crawler-seed/        # 播种（发现单元 → 建 Job → 投递首页任务）
│   ├── crawler-scheduler/   # 周期调度（定时/增量采集）
│   └── crawler-cli/         # 运维 CLI（initdb/stats/sources/dead/requeue/reset）
├── internal/
│   ├── domain/              # 领域契约（零外部依赖）
│   │   ├── task/            #   任务载荷 + 游标定位 + 翻页语义
│   │   ├── record/          #   Canonical Record
│   │   ├── cursor/          #   采集游标状态机
│   │   ├── job/             #   作业模型
│   │   └── source/          #   Adapter 契约 + UnitSink
│   ├── adapter/             # 数据源适配器
│   │   ├── registry.go      #   路由注册表
│   │   ├── factory.go       #   按 config.sources 装配
│   │   ├── coerce/          #   JSON 宽松转换
│   │   ├── shikues/         #   元器件站（JSON API + 编码式参数）
│   │   └── maccms/          #   MacCMS V10 接口
│   ├── crawler/             # Runtime
│   │   ├── engine.go        #   组件装配
│   │   ├── producer.go      #   播种
│   │   └── worker.go        #   消费主循环 + 成功/失败处理
│   ├── infrastructure/
│   │   ├── redisstore/      # Stream / 游标 / 统计 / 死信 / 作业控制
│   │   ├── mysqlstore/      # 建库建表 + 仓储（units/jobs/records）
│   │   ├── httpx/           # 统一 HTTP 客户端（重试/代理/TLS/限速）
│   │   ├── proxy/           # 代理池（轮换/冷却/熔断/Redis 化）
│   │   └── observability/   # 日志
│   ├── transport/httpapi/   # HTTP 路由与处理器
│   ├── config/              # 配置（YAML + CW_* 环境变量覆盖）
│   └── appkit/              # 命令引导
├── migrations/              # 内置 SQL 迁移（//go:embed 自动执行）
├── configs/config.yaml      # 统一配置
├── deploy 相关：Dockerfile / docker-compose.yml / Makefile / .env.example
└── go.mod
```

---

## 4. 快速开始

### 4.1 准备

```bash
# 需要 Go 1.23+、MySQL、Redis（或直接用 docker compose）
cp .env.example .env        # 按需修改
```

### 4.2 构建

```bash
make tidy
make build                  # 产物在 bin/
```

### 4.3 运行（本地进程）

```bash
make initdb                 # 建库建表（幂等）
make seed-maccms            # 播种 MacCMS 示例分类（无需源站账号）
make run-worker             # 另开一个终端启动 Worker
make stats                  # 查看任务流 / PEL / 死信 / 记录分布
```

### 4.4 运行（Docker）

```bash
docker compose up -d --build
curl http://localhost:8088/api/v1/health
curl -X POST http://localhost:8088/api/v1/jobs \
     -H 'Content-Type: application/json' \
     -d '{"source":"maccms","max_pages":2}'
```

---

## 5. 任务生命周期

```
Producer 播种
  ├─ Adapter.Discover()            发现采集单元（写入 source_types 目录）
  ├─ db.CreateJob()                创建 crawl_jobs
  ├─ redis.InitCursor()            初始化 cur:{source}:{entity}:{unit_id}
  └─ redis.XADD(tasks)             投递首页任务 (page=1)

Worker 消费
  ├─ ClaimBatch()                  XPENDING(IDLE) + XCLAIM 接管超时消息
  ├─ ReadBatch()                   XREADGROUP 读新消息
  ├─ Adapter.ExecuteList()         连接 → 抽取 → 归一化
  ├─ db.UpsertRecords()            唯一键幂等 upsert
  ├─ 未到末页 → XADD(page+1)        加锁 addlock 防重复投递
  └─ XACK

失败处理
  ├─ attempt < max_attempts        保留 PEL（不 ACK），待下次接管重试
  └─ attempt >= max_attempts       XADD(tasks:dead) + ACK + 游标置 dead
```

---

## 6. PHP → Go 映射

| PHP | Go | 说明 |
| --- | --- | --- |
| `bin/seed.php` | `cmd/crawler-seed` + `crawler/producer.go` | 播种 |
| `bin/worker.php` | `cmd/crawler-worker` + `crawler/worker.go` | 消费主循环 |
| `bin/stats.php` | `cmd/crawler-cli stats` | 统计 |
| `bin/init_db.php` | `cmd/crawler-cli initdb` / 引擎启动自动迁移 | 建表 |
| `bin/reset.php` | `cmd/crawler-cli reset` | 清理状态 |
| `src/RedisStore.php` | `internal/infrastructure/redisstore/*` | 拆为 stream/cursor/stats/control |
| `src/Worker.php` | `internal/crawler/worker.go` | — |
| `src/Producer.php` | `internal/crawler/producer.go` | — |
| `src/Contract/Task.php` | `internal/domain/task/task.go` | 载荷契约 |
| `src/Contract/SourceAdapter.php` | `internal/domain/source/source.go` | Adapter 契约 |
| `src/Adapter/ShikuesAdapter.php` | `internal/adapter/shikues/*` | 含 normalizer |
| `src/Adapter/MacCmsAdapter.php` | `internal/adapter/maccms/adapter.go` | — |
| `src/Adapter/AdapterFactory.php` | `internal/adapter/factory.go` | — |
| `src/Adapter/AdapterRegistry.php` | `internal/adapter/registry.go` | — |
| `src/Db.php` | `internal/infrastructure/mysqlstore/*` | 拆为 db/units/jobs/records |
| `src/Http.php` | `internal/infrastructure/httpx/client.go` | 统一抓取层 |
| `src/ProxyPool.php` | `internal/infrastructure/proxy/pool.go` | 增加熔断/冷却 |
| `src/Logger.php` | `internal/infrastructure/observability/logger.go` | — |
| `src/config.php` | `internal/config/config.go` + `configs/config.yaml` | 增加环境变量覆盖 |
| `sql/schema.sql` | `migrations/001_init.sql`（embed 自动执行） | 表结构兼容 |
| — | `internal/transport/httpapi/*` | **新增**：编排 API |

### Redis Key 对照（前缀默认 `cw:`，与 PHP 版一致）

| Key | 类型 | 用途 |
| --- | --- | --- |
| `tasks` | Stream | 任务队列 |
| `tasks:dead` | Stream | 死信队列 |
| `cur:{source}:{entity}:{unit_id}` | Hash | 采集游标状态机 |
| `addlock:{source}:{entity}:{unit_id}:{page}` | String | 追加下一页幂等锁 |
| `attempt:{msg_id}` | String | 消息重试计数（1 天过期） |
| `stats` | Hash | 全局统计计数 |
| `proxy:pool` / `proxy:pool:rr` | Hash / String | 代理池状态 / 轮询计数 |
| `job:{id}:state` | String | 作业控制：running/paused/cancelled |
| `job:{id}:parked` | List | 暂停期间暂存的任务 |

### MySQL 表

`source_types`、`crawl_jobs`、`crawl_records` 与 PHP 版**列名/唯一键完全一致**，可共用同一个库；
Go 版额外增加 `crawl_agent_events`（编排/审计，PHP 版可忽略）。

---

## 7. HTTP API（Orchestrator / Workbench 后端）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/v1/health` | 健康检查（Redis/MySQL） |
| GET | `/api/v1/sources` | 数据源与采集单元目录（`?probe=1` 探测可用性） |
| GET | `/api/v1/metrics` | 任务流/PEL/死信/统计/记录分布/代理池 |
| GET | `/api/v1/jobs` | 作业列表（`?source=&limit=`） |
| POST | `/api/v1/jobs` | 创建作业（`{source, units[], max_pages, force}`） |
| GET | `/api/v1/jobs/{id}` | 作业详情（含运行时状态与暂存任务数） |
| POST | `/api/v1/jobs/{id}/cancel` | 取消作业（Worker 会跳过并收尾） |
| POST | `/api/v1/jobs/{id}/pause` | 暂停作业（未处理任务暂存） |
| POST | `/api/v1/jobs/{id}/resume` | 恢复作业（暂存任务重新入流） |
| GET | `/api/v1/dead` | 死信清单 |
| POST | `/api/v1/dead/requeue` | 死信重投（`{ids[], limit}`） |
| GET | `/api/v1/results` | 结果查询（`?source=&entity=&unit_id=&keyword=&limit=`） |

---

## 8. 配置

优先级：**内置默认值 < `configs/config.yaml` < `CW_*` 环境变量**。

关键项：

```yaml
default_source: shikues
sources:                 # Source Definition：新增数据源在此声明
  shikues: {adapter: shikues, entity: model, type: 1, api_base: ...}
  maccms:  {adapter: maccms,  entity: vod,   api_base: ..., units: [...]}
task:
  page_limit: 15         # 站点每页行数（Runtime 统一注入）
  max_attempts: 3        # 超过转死信
  stream/group/dead_stream/stats_key
worker: {batch: 5, block_sec: 5, idle_rounds: 30}
http:   {timeout_sec, retries, ua, referer, delay_ms, ssl_verify, proxy{...}}
```

常用环境变量：`CW_SOURCE`、`CW_MAX_PAGES`、`CW_MYSQL_*`、`CW_REDIS_*`、`CW_DELAY_MS`、
`CW_SSL_VERIFY`、`CW_PROXY_ENABLED`、`CW_PROXY_MODE`、`CW_PROXY_LIST`、`CW_API_BASE`、`CW_MACCMS_*`。

---

## 9. 横向扩展

```bash
# 同一消费组下启动多个 Worker 即可水平扩展（consumer 名必须唯一）
bin/crawler-worker -consumer worker-01 -idle-rounds -1 &
bin/crawler-worker -consumer worker-02 -idle-rounds -1 &
bin/crawler-worker -consumer worker-03 -idle-rounds -1 &
```

崩溃/超时消息由 `XPENDING(IDLE) + XCLAIM` 自动被其他 Worker 接管；失败超限进入死信流，
不再阻塞主链。

---

## 10. 扩展新数据源（Adapter）

1. 在 `internal/adapter/<source>/` 实现 `source.Adapter`：
   ```go
   type Adapter interface {
       Source() string
       Discover(ctx, sink) ([]task.Unit, error)
       ExecuteList(ctx, t *task.Payload) (*source.ListResult, error)
       HealthCheck(ctx) error
   }
   ```
2. 在 `internal/adapter/factory.go` 增加分派分支；
3. 在 `configs/config.yaml` 的 `sources` 下增加声明。

Runtime、Redis Key、MySQL 表、API **均无需改动**。

---

## 11. 演进路线（对应实现状态）

| 阶段 | 内容 | 状态 |
| --- | --- | --- |
| Phase 1 | PHP → Go Runtime（Stream/Worker/Producer/Adapter/MySQL/Retry/Claim/DeadLetter/Cursor） | ✅ 已完成 |
| Phase 2 | 通用采集引擎（Task/Job/Source/Unit/Record/Proxy/RateLimit） | ✅ 已完成 |
| Phase 3 | API + Scheduler（`POST /jobs` 等） | ✅ 已完成 |
| Phase 4 | Workbench（Dashboard/Sources/Plans/Schema/Jobs/Workers/DeadLetters） | ⏳ 待建（消费本 API） |
| Phase 5 | Crawler Agent（Analyze/Discover/Plan/Schema/Repair） | ⏳ 待建（复用 `crawl_agent_events` 审计） |

---

## 12. 后续增强建议

- **Schema 驱动**：`Source → Schema → Adapter → Canonical Record`，让 Workbench 提供 Schema Editor；
- **内容指纹**：`crawl_records` 增加 `content_hash = sha256(canonical_json)` + `first_seen_at/last_seen_at`，
  用于识别「新增 / 重复 / 变更」；
- **错误分类**：把 `network/timeout/http_429/http_403/parse/schema/db` 结构化写入死信，供 Agent 自动修复；
- **指标**：接入 Prometheus（`/metrics`）与 OpenTelemetry Trace；
- **熔断与限速**：按域名维度的令牌桶 + 断路器。

---

## 13. 开发

```bash
make fmt      # gofmt
make vet      # go vet
make test     # 单元测试
make build    # 全量构建
```

已覆盖单元测试：任务载荷/翻页语义、shikues 清洗规则、配置加载与环境变量覆盖。
