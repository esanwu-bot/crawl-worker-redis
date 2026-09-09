# Crawler Agent（Agent 化版本）

本目录是 `crawl-worker-redis` 的 **Agent 化演进版本**，在保留原 Crawler Engine 不变的前提下，新增 **Agent Orchestrator** 与 **Workbench UI** 两层。

## 1. 三层职责

```
Workbench UI        Agent Orchestrator        Crawler Engine
(ui/index.html)     (src/agent/*)             (src/* + bin/*)
     |                       |                         |
     +-- 自然语言意图 ------> |                         |
                           Planner                   |
                           Workflow                  |
                           EngineCapability ---------> Producer / Worker / RedisStore / Db
```

- **Crawler Engine**：原有采集执行层（`Producer`、`Worker`、`RedisStore`、`Db`、`Normalizer`、`ApiClient`）。P1 已做工具化改造：
  - 任务 payload 透传 `target_pages` 与 `job_id`
  - 新增 `RedisStore::readDead` / `requeueDead` 死信运维
  - 新增 `Db::searchTypes` / `searchModels` 供 Agent 做计划与校验
  - 新增 `Worker` 对 `cancelled/paused` 任务控制信号的响应
  - MySQL schema 新增 `agent_jobs` / `agent_workflow_runs`

- **Agent Orchestrator**：
  - `JobIntent` / `Plan` / `PlannerInterface` / `RulePlanner`：意图 → 计划
  - `EngineCapability`：把 Engine 包装成 Agent 可调用的能力单元
  - `JobRepository` / `Workflow`：Job 状态持久化与执行流程（plan → approval → seed → crawl → done）
  - `Validator`：输入校验与安全兜底

- **Workbench UI**：单页 HTML/JS，支持提交意图、审批计划、查看任务详情、发送 pause/resume/cancel、查看引擎指标与死信队列。

## 2. 环境要求

- PHP >= 8.1（P3 接入 Neuron 时需要 8.1+；当前 P2 规则 Planner 在 8.0+ 亦可运行）
- 扩展：`redis`、`pdo_mysql`、`curl`、`openssl`、`mbstring`
- MySQL / Redis（同原项目）

> 当前在 PHP 8.2.9 上运行时，需先确保以上扩展均已启用；若缺少 `php_redis.dll`，需从 PECL 下载对应 8.2 NTS x64 版本放到 `ext` 目录并在 `php.ini` 中启用。

## 3. 快速开始

```bash
# 1. 首次建库（已自动包含 agent_jobs / agent_workflow_runs）
php bin/init_db.php

# 2. CLI 跑一次完整 Agent 闭环（自动审批）
php bin/orchestrator.php run --intent "帮我采集 MOSFET 系列，每个系列抓 2 页" --approve

# 3. 启动 HTTP 服务 + Workbench UI
php bin/orchestrator.php serve --port 8787
# 打开 http://127.0.0.1:8787/
```

## 4. CLI 命令

```bash
php bin/orchestrator.php run --intent "..." [--approve] [--id <jobId>]
php bin/orchestrator.php approve --id <jobId>
php bin/orchestrator.php control --id <jobId> --action pause|resume|cancel
php bin/orchestrator.php status --id <jobId>
php bin/orchestrator.php serve --host 127.0.0.1 --port 8787
```

## 5. HTTP API

| 方法 | 路径 | 说明 |
|------|------|------|
| GET  | `/api/health` | 健康检查 |
| GET  | `/api/stats` | 引擎指标 |
| GET  | `/api/jobs` | 任务列表 |
| POST | `/api/jobs` | 创建/执行任务 `{intent, approve?}` |
| GET  | `/api/jobs/{id}` | 任务详情 |
| POST | `/api/jobs/{id}/approve` | 审批通过并继续执行 |
| POST | `/api/jobs/{id}/control` | 控制信号 `{action: pause/resume/cancel}` |
| GET  | `/api/dead` | 死信列表 |
| POST | `/api/dead/requeue` | 重投死信 `{ids: [...]}` |

## 6. 配置

配置入口仍为 `config/config.php`，新增 `agent` 段，支持环境变量覆盖：

- `CW_AGENT_LLM_PROVIDER` / `CW_AGENT_LLM_KEY` / `CW_AGENT_LLM_BASE_URL` / `CW_AGENT_LLM_MODEL`
- `CW_AGENT_TARGET_PAGES`：默认每个系列抓多少页
- `CW_AGENT_MAX_TYPES`：单次意图最多匹配系列数
- `CW_AGENT_AUTO_WORKER`：是否自动启动 worker（P3 使用）

## 7. 演进阶段

- **P1** ✅：Engine 工具化改造
- **P2** ✅：确定性规则 Orchestrator + Workbench UI（当前已落地）
- **P3** 🚧：接入 LLM（Neuron AI 等），替换 `RulePlanner` 为 `NeuronPlanner`。只需实现 `PlannerInterface` 并在 `bin/orchestrator.php` 中切换即可。
