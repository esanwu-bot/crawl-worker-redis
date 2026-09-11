# 架构说明

## 1. 分层与依赖方向

```
        cmd/*             （可执行入口，只做参数解析与信号处理）
          │
          ▼
   transport/httpapi      （HTTP 适配：请求 → 应用服务）
          │
          ▼
      crawler             （Runtime：Engine / Producer / Worker）
          │
   ┌──────┴───────┐
   ▼              ▼
 adapter      infrastructure
   │              │
   └──────┬───────┘
          ▼
       domain             （契约，零外部依赖）
```

依赖只允许**从外向内**：

- `domain` 不 import 任何内部包；
- `crawler` 只能 import `domain`、`config`、`infrastructure`、`adapter`（用于装配）；
- `adapter` 只能 import `domain`、`infrastructure/httpx`，**不得** import `crawler` / `transport`；
- `infrastructure` 不 import `crawler` / `transport` / `adapter`。

这样 Runtime 永远不出现 `if source == "shikues"` 这类分支。

---

## 2. 三个核心契约

### 2.1 Task Payload（任务契约）

`internal/domain/task.Payload`，即 Redis Stream 消息 `p` 字段：

```json
{
  "job_id": "job-20260911...",
  "source": "shikues",
  "entity": "model",
  "operation": "list",
  "cursor": {"unit_id": "3", "unit_name": "肖特基二极管", "page": 1},
  "params": {"limit": 15},
  "target_pages": 3
}
```

- `operation` 为扩展点：V1 只用 `list`，未来可加 `detail` / `search` / `export`；
- `cursor` 同时用于**定位 Redis 游标 Hash**（`CursorKey()`）与**描述进度**。

### 2.2 Canonical Record（结果契约）

`internal/domain/record.Canonical`，任何数据源都必须清洗成它：

- 三件套：`canonical`（结构化字段）+ `payload`（领域可检索）+ `raw`（原始备份）；
- 唯一键 `(source, entity, unit_id, external_id)` 保证跨源、跨批的幂等。

### 2.3 Source Adapter（数据源契约）

```go
type Adapter interface {
    Source() string
    Discover(ctx context.Context, sink UnitSink) ([]task.Unit, error)
    ExecuteList(ctx context.Context, t *task.Payload) (*ListResult, error)
    HealthCheck(ctx context.Context) error
}
```

- `Discover` 负责「目录」：把采集单元写入 `source_types` 并返回；
- `ExecuteList` 负责「一页」：连接 → 抽取 → 归一化，返回 `Canonical Record`；
- Adapter 内部可再拆 `client / parser / normalizer / mapper`（`shikues` 即如此），
  但对外只暴露上述四方法。

---

## 3. Worker 主循环

```
for {
    msgs = ClaimBatch()        // XPENDING(IDLE) + XCLAIM：接管超时/失联消息
    if empty { msgs = ReadBatch() }   // XREADGROUP '>'
    if empty { idle++; if idle>=N { return } ; continue }
    for msg in msgs { process(msg) }
}
```

`process` 的决策树：

```
载荷非法 ──────────────────────► 丢弃 + ACK
作业 cancelled ────────────────► 游标置 cancelled + ACK
作业 paused ───────────────────► 载荷暂存 job:{id}:parked + ACK
Adapter 缺失 ──────────────────► 死信 + ACK
ExecuteList 成功
    ├─ 落库成功
    │   ├─ 已到末页/页数上限 ──► unit_done + 游标 done + ACK
    │   └─ 否 ────────────────► addlock 加锁 + XADD(page+1) + ACK
    └─ 落库失败 ──────────────► 按失败处理
ExecuteList 失败
    ├─ attempt < max_attempts ► 游标记 attempts + 保留 PEL（不 ACK）
    └─ attempt >= max ────────► 死信 + unit_dead + 游标 dead + ACK
```

**关键点**：失败消息**不 ACK**，留在 PEL 中，由 `XPENDING(IDLE)+XCLAIM` 在超时后被重新接管，
这样崩溃恢复与重试是同一条路径，无需额外重试队列。

---

## 4. 并发与幂等

| 风险 | 对策 |
| --- | --- |
| 多 Worker 同时处理同单元下一页 | `addlock:{...}:{page}` 用 `SETNX` 加锁，只有抢到锁的才追加下一页 |
| 重复投递同一条记录 | MySQL 唯一键 `(source, entity, unit_id, external_id)` upsert |
| Worker 崩溃导致消息卡在 PEL | `claim_idle_ms` 超时后由其他 Worker `XCLAIM` 接管 |
| 单条任务无限阻塞 | 每次执行带 `context.WithTimeout`（基于 `http.timeout_sec × retries`） |

---

## 5. 作业控制（pause / cancel / resume）

- `job:{id}:state`：`running` / `paused` / `cancelled`；
- Worker 每条任务处理前读取状态：
  - `cancelled` → 游标置 cancelled、ACK、跳过；
  - `paused` → 载荷写入 `job:{id}:parked`、ACK（避免长期占用 PEL）；
- `resume` → 把 `parked` 列表重新 `XADD` 回任务流并置回 `running`。

该设计使「暂停」不产生忙等，也不丢任务。

---

## 6. 可观测性

- 日志：`internal/infrastructure/observability`，控制台 + 文件双写；
- 统计：Redis Hash `stats`（`loop/idle/page_ok/page_retry/page_dead/unit_done/unit_dead/rows/...`）；
- 任务流健康：`stream_len` / `pending(PEL)` / `dead_letters`；
- HTTP：`/api/v1/metrics` 汇总输出，供 Workbench 展示。

---

## 7. 与后续 Agent 化对接

```
Crawler Agent
    ├─ Analyze Source      → GET  /api/v1/sources?probe=1
    ├─ Generate Plan       → POST /api/v1/jobs
    ├─ Observe Progress    → GET  /api/v1/jobs/{id}
    ├─ Analyze Errors      → GET  /api/v1/dead
    └─ Repair Tasks        → POST /api/v1/dead/requeue
```

Agent 只与本 API 交互，不直接触碰 Redis/MySQL，从而保证「Agent 决策、Runtime 执行」的边界清晰；
所有行为可写入 `crawl_agent_events` 审计。
