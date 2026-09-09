# Handoff — Agent 化爬虫（P3：Neuron v3 规划节点已接入）

> 记录时间：2026-09-09
> 对应设计文档：`Agent化设计-CrawlerEngine-Orchestrator-Workbench.md`（P3「Neuron v3 Agent Harness 落地」）
> 目录：本文件位于 `agentVersion/`，是主仓库 git 下的独立 Composer 子工程

---

## 1. 现状一句话

Agent 编排的 **plan 阶段已从「curl 直连 LLM」（P2 `LlmPlanner`）升级为 Neuron v3 Agent Harness 节点**（P3 `NeuronPlanner`），并已用真实 SenseNova 网关端到端跑通：Agent 在 Harness 内完成一次 `list_series` 工具调用往返，产出 JSON 计划，经确定性收敛生成 `Plan`。

## 2. 调用链（当前实现）

```
bin/orchestrator*.php --intent "..."              (CLI/HTTP 入口)
  └─ PlannerFactory::create(db, log, cfg.agent)   (自动选择)
       └─ NeuronPlanner      <- P3 新增，Harness 化
            ├─ PlannerAgent  extends NeuronAI\Agent\Agent   (子类化模式)
            │    ├─ provider()    -> OpenAILike(SenseNova 网关)
            │    ├─ instructions()-> 规划约束 System Prompt
            │    └─ tools()       -> NeuronTools::forPlanner()
            │                          └─ list_series（Engine 只读目录，function calling）
            ├─ 确定性收敛：type_id 只保留真实目录中存在的
            └─ 异常自动回退 → LlmPlanner → RulePlanner
```

- **闭合世界安全约束保持不变**：LLM 只能从注入的真实目录「选择」type_id，不能编造；最终输出经 DB 目录反查过滤。
- 所有写操作（seed/crawl/dispatch）仍由上层确定性 `Workflow` 执行，Agent 不持任何写工具。

## 3. 本阶段改动清单

| 文件 | 类型 | 说明 |
|---|---|---|
| `agentVersion/composer.json` | 修改 | 新增 `neuron-core/neuron-ai: ~3.16.0` |
| `agentVersion/composer.lock` | 新增 | 锁定依赖树（vendor 不入库） |
| `agentVersion/src/agent/Neuron/PlannerAgent.php` | 新增 | Neuron `Agent` 子类：provider/instructions/tools 三钩子 + `toolRunLog` 审计 |
| `agentVersion/src/agent/Neuron/NeuronTools.php` | 新增 | Engine 只读能力 `list_series` → Neuron `Tool`（JSON Schema 参数） |
| `agentVersion/src/agent/Neuron/NeuronPlanner.php` | 新增 | 实现 `PlannerInterface`：注入候选目录、宽容解析 JSON、目录反查收敛、异常回退 |
| `agentVersion/src/agent/PlannerFactory.php` | 修改 | 分级选择：Neuron v3 已装 → NeuronPlanner；否则 LlmPlanner/RulePlanner |
| `agentVersion/bin/orchestrator.php` | 修改 | 守卫式 require `vendor/autoload.php`（未安装时降级旧路径） |
| `agentVersion/bin/orchestrator-router.php` | 修改 | 同上 |
| `agentVersion/samples/neuron_check.php` | 新增 | 端到端冒烟 + 自检（exit 0 才算过） |

## 4. 环境与依赖

- PHP >= 8.1；ext-curl / json / pdo / redis。
- 安装：`cd agentVersion && composer install`（`composer.lock` 已锁定）。
- **已知环境坑**：本机 phpstudy PHP 未开 `extension=zip`、系统无 unzip/7z → Composer dist 解压失败，被迫走 git clone 才装上。建议 php.ini 开启 zip 后再 `composer update` 提速。
- `vendor/` 不入库（已在 `agentVersion/.gitignore` 增加 `vendor/`）。

## 5. 配置（agentVersion/.env，不入库）

```
CW_AGENT_LLM_BASE_URL=https://token.sensenova.cn/v1
CW_AGENT_LLM_KEY=<key>
CW_AGENT_LLM_MODEL=sensenova-6.8-flash-lite
CW_AGENT_LLM_TIMEOUT=30
```

Provider 切换 = 改 `base_url/key/model` 即可换任意 OpenAI 兼容网关。

## 6. 验证方式

```bash
# 1) LLM 网关连通性（不依赖 Agent）
php agentVersion/samples/llm_check.php
# 2) Neuron v3 节点端到端（实测 4.1s，exit 0）
php agentVersion/samples/neuron_check.php "把肖特基低正向压降那个系列整个采下来，先采 3 页" --json
```

实测输出要点（2026-09-09）：

- `Planner = Cw\Agent\Neuron\NeuronPlanner`（Harness 自动选中）
- `metadata.tool_runs`：Agent 真实执行 `list_series(keyword=肖特基)` 1 次
- 收敛结果：`type_ids=[1]`（Low VF Schottky Diodes）、`target_pages=3`、`requires_approval=true`
- 语义自检断言：planner=neuron 且发生过工具调用，否则 exit 1

## 7. 注意事项 / 已知取舍

- **SSL**：`PlannerAgent` 沿用仓库 curl 直连的做法设了 `verify=false`（phpstudy 无系统 CA）。生产环境应从配置打开校验或提供 CA bundle，勿直接上线。
- **工具参数**：Neuron Tool 的 optional 属性缺省会显式传 `null`，工具回调内需自行归一（已处理）。
- **structured() 未启用**：Neuron 支持 `$agent->structured()` 结构化输出，但需 Provider 支持 `response_format=json_schema`；SenseNova 6.8 网关走的是「JSON 文本 + 确定性解析」，语义等价、兼容性更稳。迁移点已留好（见下）。
- 仓库根目录的 `Agent化设计-*.md` 与 `README - 副本.md` 为工作区未跟踪文档，是否入库由你决定（本次提交未包含）。

## 8. 下一步（Roadmap 备注）

1. `CrawlRunWorkflow`：用 Neuron Workflow（Events/InterruptRequest/持久化）承接 plan 之后 seed→crawl 的编排，`plan_step` 作为节点；审批用 human-in-the-loop Interruption。
2. `ReflectorAgent` / `CrawlJudge`：对采集结果复盘、失败原因归因、再规划闭环。
3. 引入 `CrawlPlan` DTO（`#[SchemaProperty]`）并切 `structured()`，前提是网关支持 json_schema。
4. 把 `NeuronPlanner` 的候选注入/收敛逻辑提炼为通用 `EngineCapability` 契约，供后续节点复用。
