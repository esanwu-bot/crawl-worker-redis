# crawl-worker-redis Agent 化设计：Crawler Engine + Agent Orchestrator + Workbench UI

> 本文是《多进程高并发解决方案》《代理 IP 池方案》之后的第三层设计：不推翻现有 Redis Stream 任务层与 MySQL 结果层，
> 而是在其之上加"大脑（Agent Orchestrator，构建于 **Neuron AI v3 Agent Harness**）"与"脸（Workbench UI）"，
> 把采集系统从**命令驱动 CLI**升级为**目标驱动 Agent**。
> 用户只需在界面上说一句"采什么"，LLM 负责规划，现有 Engine 负责可靠执行。

---

## 一、定位：从「命令驱动」到「目标驱动」

当前系统是一个优秀的 **Crawler Engine**，但指挥它全靠人肉命令行：

```bash
php bin/seed.php  --types 1,3 --max-pages 3   # 人告诉机器：采哪个系列、采几页
php bin/worker.php --name w1 --idle-rounds 0   # 人告诉机器：开几个进程
php bin/stats.php                              # 人自己看结果
```

Agent 化之后，交互范式变成：

```
用户："帮我把'低正向压降肖特基'那个系列整个采下来，封装和 PDF 都要，
      抓取失败自动重试一轮，采完把结果整理成表给我。"

  │  Workbench UI（对话 + 计划审批 + 进度看板 + 结果表）
  ▼
  Agent Orchestrator   =  Neuron AI v3 Agent Harness 上的编排 Workflow
  │                      （LLM 规划：意图→检索站内目录→生成计划→验证→审批→调度→观测→交付）
  │                      只调用 Engine 暴露的「能力工具」，写操作经 Workflow Interruption 人工审批
  ▼
  Crawler Engine（现状原样保留：Redis Stream 消费组 + XCLAIM 崩溃接管 +
                   MySQL 幂等落库 + 断点游标）
```

> 一句话：**Engine 负责"稳定地把一件确定的事做对"，Orchestrator 负责"把模糊的意图变成确定的事"，
> Harness（Neuron v3）负责"让 Orchestrator 可工程化落地"，UI 负责"人和机器对话"。**

---

## 二、三层职责边界（含 Harness 底座）

| 层 | 角色 | 职责 | 是否自主 | 现状 |
| --- | --- | --- | --- | --- |
| **Workbench UI** | 脸 | 自然语言会话、计划预览与审批、系列/任务看板、结果浏览与导出、死信干预 | 无 | 无（需新增 `ui/`） |
| **Agent Orchestrator** | 脑 | 意图解析、目录检索、Plan 生成、确定性校验、调度、执行观测、失败反思、交付报告 | LLM 只做"规划与解释"，**写操作全部走工具契约 + 人工审批** | 无（需新增，落于 Neuron v3） |
| └ **Agent Harness** | 底座 | agent loop / function calling / 多 Provider 抽象 / 结构化输出 / Workflow 状态与持久化 / 人工介入 / 流式 / 观测 | — | 无（`composer require neuron-core/neuron-ai`） |
| **Crawler Engine** | 手 | 抓取、清洗、幂等落库、断点续采、崩溃恢复、重试/死信、限速、代理轮换 | 无（纯确定性执行） | `bin/*` + `src/*` **原样复用** |

**一个关键原则：LLM 永不直接写 Redis / MySQL / 发请求。**
LLM 只产出结构化 Plan；Plan 经"确定性校验器"（普通 PHP 代码）校验后，由 Workflow 调用 Engine 的能力执行。
LLM 的幻觉被限制在"计划层"，不触碰数据层可靠性。Neuron 的角色是把"LLM 规划 + 确定性执行 + 人工审批"这件事用工程原语拼装出来，而不是让应用自己造轮子。

---

## 三、现有资产归位（复用映射）

现有代码在三层里的位置——**约 90% 代码原样保留**：

| 现状文件 | 在三层中的角色 | 复用什么 |
| --- | --- | --- |
| `RedisStore.php` | Engine · 任务层 | 全部复用：Stream `tasks`、死信 `tasks:dead`、游标 `cur:{type_id}`、`attempt:*`、`stats`、XPENDING+XCLAIM 接管 |
| `Producer.php` | Engine · 播种 | 复用 `Producer::run($wantTypes,$force)` → 成为能力 `seed_crawl` 的后端 |
| `Worker.php` | Engine · 消费 | 复用：`start/process/onFail`，多开横向扩容 |
| `ApiClient.php` | Engine · 站点接口 | 复用：`productTypes()` / `productList()` → 能力 `list_series` / 抓取后端 |
| `Normalizer.php` | Engine · 清洗 | 复用 `toModelRows()`（a..t 全列入 specs_json，天然异构） |
| `Db.php` + `sql/schema.sql` | Engine · 结果层 | 复用：`source_types` / `product_models`，`(source,model)` 幂等 upsert |
| `Http.php` / `ProxyPool.php` | Engine · 基础设施 | 复用：超时/重试/限速/代理轮换 |
| `bin/init_db|seed|worker|stats|reset.php` | Engine · 命令面 | 原样保留，兼作 Orchestrator 工具底座的 CLI 入口 |
| `config/config.php` | 全局 | 复用 + 新增 `agent` 段（LLM/预算/审批策略） |
| 无 | Orchestrator | **新增** `src/agent/`（Engine 能力工具 + Plan DTO + Neuron Agent/Workflow） |
| 无 | Orchestrator 依赖 | **新增** `composer.json` + `neuron-core/neuron-ai`（PHP ^8.1） |
| 无 | Workbench UI | **新增** Web 前端（`ui/`） |

> 即：不改 `Worker` 的失败语义、不碰 `XCLAIM` 接管、不重写 `Normalizer`。Agent 化 = **加层不改底**。

---

## 四、Agent Orchestrator：构建在 Neuron AI v3 之上

### 4.1 为什么 Harness 选 Neuron AI v3

| 决策点 | Neuron v3 提供的答案 |
| --- | --- |
| 语言匹配 | **纯 PHP**（composer `neuron-core/neuron-ai`，PHP ^8.1）。Engine 全栈 PHP，Orchestrator 可直接 `require src/bootstrap.php` 本地调用现有类，无需跨语言微服务与二次序列化 |
| 编排骨架 | v3 以 **Workflow** 为核心重构：Multi-Step / Loops & Branches / State / Persistence / **Interruption**。我们的"审批-调度-观测-反思"正是它的原生场景 |
| 人工审批 | **Workflow Interruption（human-in-the-loop）**：流程可挂起等待 UI 批准/驳回，天然承载我们的写操作闸门 |
| 计划强约束 | **Structured Output**（`#[SchemaProperty]` DTO）让 Plan 输出为强类型对象，"格式幻觉"在框架层即被消除 |
| 工具契约 | **Tools / Toolkits / ToolProperty** 自动生成 LLM 可见的 JSON Schema，支持 function calling 闭环 |
| LLM 可切换 | `AIProviderInterface`：OpenAI / OpenAILike / Anthropic / Deepseek / Gemini / Ollama / Bedrock 等一行切换，本地或云上模型都可跑 |
| 会话/记忆 | Agent 默认 Memory；UI 会话与 Agent 对话天然串联 |
| 观测 | Inspector 面板（执行时间线）+ 我方 `crawl_agent_events` 审计双轨 |
| 渐进能力 | MCP Connector / RAG / Async / Streaming（含 AG-UI、Vercel AI SDK protocol），为 P4/P5 留口 |

### 4.2 Neuron 原语 ↔ 本设计概念映射

| 本设计概念 | Neuron v3 原语 | 说明 |
| --- | --- | --- |
| Planner / Reflector（两个 LLM 角色） | `Agent` 子类（`provider()` + `instructions()` + `tools()`） | agent loop / memory / function calling 由 Harness 托管，我们只写业务方法 |
| `CrawlPlan` 结构化产出 | `Structured Output`（`#[SchemaProperty]` DTO） | `$agent->structured(...)` 直接返回强类型 `CrawlPlan` |
| 意图→执行全流程 | `Workflow`（多步骤 + State） | `CrawlRunWorkflow` 承载 1..N 步，状态存 `crawl_jobs` |
| 写操作人工审批 | **Workflow Interruption** | 流程在审批点挂起，UI 批准后恢复 |
| Monitor 轮询 / 死信修补 | Workflow **Loop & Branches** | 未完成→循环；dead>0→分支到 ReflectorAgent |
| Job 长期状态 | Workflow **Persistence** + 我方 `crawl_jobs` 表 | checkpoint 化，进程重启可续 |
| Engine 能力契约 | **Tools / Toolkits**（内部再包一层 `EngineCapability` 适配器） | 每个工具 = 现有 `RedisStore/ApiClient/Producer/Db` 方法的薄封装 |

### 4.3 Orchestrator = Workflow 骨架（确定性）+ 两个 Neuron Agent（LLM）

```
┌──────────────────────── Neuron AI v3 Agent Harness ────────────────────────┐
│  CrawlRunWorkflow（Workflow：state / interruption / loop&branch / persist）│
│                                                                             │
│  ① intent_step    收到 UI 意图 → 落 job(draft)                              │
│  ② plan_step      PlannerAgent(Neuron Agent) 调 list_series 后              │
│                    Structured Output → CrawlPlan DTO                        │
│  ③ validate_step  纯代码校验（type_id 必须来自真实目录/预算/风险）            │
│                    不合法 → 回 ② 自纠（≤2 次），仍失败转人工                  │
│  ④ approval_step  ⛔ Workflow Interruption（human-in-the-loop）              │
│                    挂起，等 UI Approve / Reject / 会话预授权直放            │
│  ⑤ dispatch_step  seed_crawl 工具 → job(running)；Worker 已由 Supervisor 托管│
│  ⑥ monitor_loop   🔁 Loop：get_progress / get_metrics 轮询                  │
│                     ├─ dead>0 → ReflectorAgent 修补 → requeue → 回 ⑤/⑥     │
│                     └─ 全部完成 → break                                     │
│  ⑦ report_step    汇总 summary_json → UI 渲染 + 审计落 crawl_agent_events   │
│                                                                             │
│  Harness 托管：agent loop / function calling / memory / provider / streaming│
│               / 观测（Inspector）；我们只写：计划指令、工具薄封装、校验规则    │
└─────────────────────────────────────────────────────────────────────────────┘
```

**关键点：LLM 只出现在 ②plan_step 与 ⑥的 ReflectorAgent 两个"产生决策"的位置；
其余步骤全部是确定性 Workflow 代码。** 这样既享受 LLM 的意图理解力，又不把可靠性押在模型自觉上。

### 4.4 端到端时序（一次"目标"的完整生命周期）

```
UI         Orchestrator(Neuron Workflow)                   Engine         LLM/Neuron
 │  ①意图文本 │                                              │
 ├──────────►│ CrawlRunWorkflow 启动                          │
 │           │ ②planner: 注入 list_series(只读) 结果          │
 │           ├──────────────────────────────────────────────►│
 │           │◄────────── 返回 CrawlPlan DTO ────────────────┤
 │           │ ③validator 校验（普通 PHP 代码）               │
 │  ④计划预览 │ (待审批 Plan + 预估范围/风险)                  │
 │◄──────────┤ Workflow Interruption 挂起                    │
 │  ⑤批准    │                                              │
 ├──────────►│ 流程恢复                                      │
 │           │ ⑥seed_crawl(type_id…) → 投首页任务            │
 │           ├──────────────────────────────────────────────►│ (Engine)
 │           │ ⑦monitor_loop 轮询 progress/metrics           │
 │  ⑧进度    │◄─────────────────────────────────────────────┤
 │◄──────────┤ (死信>0→ReflectorAgent 修补→requeue)          │
 │  ⑨交付    │ summary_json + 结果样本 → UI                  │
 │◄──────────┤                                              │
```

### 4.5 代码骨架（示意，示意 API 以 Neuron v3 文档为准）

```php
// src/agent/Neuron/PlannerAgent.php —— 继承 Neuron Agent，规划器
class PlannerAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new OpenAILike(
            key: getenv('CW_LLM_KEY'), model: getenv('CW_LLM_MODEL'),
            baseUrl: getenv('CW_LLM_BASE_URL'),       // DeepSeek/OpenAI 兼容网关/本地 Ollama 均可
        );
    }

    protected function instructions(): string
    {
        return '你是电子元器件采集规划器。把用户意图映射到 list_series 返回的真实 type_id'
             . '（禁止编造 id），遵守预算与 max_pages 上限，输出 CrawlPlan。';
    }

    protected function tools(): array
    {
        return [
            new EngineTool(new ListSeriesCapability()),   // 只读：调 ApiClient::productTypes()
            new EngineTool(new EstimateScopeCapability()),// 只读：探测第 1 页 last_page
        ];
    }
}
```

```php
// src/agent/Workflow/CrawlRunWorkflow.php —— 确定性编排骨架
$workflow = (new CrawlRunWorkflow())           // 多步骤定义在类内
    ->state(new JobState($jobId))               // Workflow Persistence ↔ crawl_jobs
    ->step(fn () => $this->plan($agent, $intent))          // ②
    ->validate(...)                             // ③ 纯代码
    ->interrupt('await_approval')               // ④ Human-in-the-loop
    ->step(fn () => $this->dispatch($plan))     // ⑤ seed_crawl 工具（write，仅此时可调）
    ->loop($this->monitor(...))                 // ⑥
    ->step(fn () => $this->report($jobId));     // ⑦
```

---

## 五、中间表示（IR）Schema

Agent 化需要一个贯通三层的数据契约，让"一句话"变成"能驱动现有 Redis 任务的确定结构"。

### 5.1 四层 IR 与现有存储的对应

```
意图 Intent ──► 计划 CrawlPlan(DTO) ──► 编排任务 CrawlJob(MySQL新增) ──► 引擎消息 Redis Stream
(不落库)        (Structured Output,      (长期记录,审批/进度/审计)        {type:'page',type_id,page} 现状载荷
                强类型 + 校验)                                               + 各系列游标 cur:{type_id}
```

### 5.2 CrawlPlan（Neuron Structured Output 的 PHP DTO）

```php
namespace App\Agent\DTO;

use NeuronAI\StructuredOutput\SchemaProperty;

class CrawlPlan
{
    #[SchemaProperty(description: '数据源', required: true)]  public string $source;
    #[SchemaProperty(description: '用户目标')]                 public string $goal = '';

    /** @var CrawlPlanItem[] 必须来自 list_series 真实目录 */
    #[SchemaProperty(description: '目标系列')]                 public array $items = [];

    #[SchemaProperty(description: '是否全字段保留')]             public bool $keepRaw = true;
    #[SchemaProperty(description: '预算: max_types/max_pages/max_rows')]
                                                               public Budget $budget;
    #[SchemaProperty(description: 'read | write')]             public string $risk = 'write';
    #[SchemaProperty(description: '规划理由')]                   public string $reasoning = '';
}

class CrawlPlanItem
{
    #[SchemaProperty(required: true)] public int $typeId;     // 站点真实系列 id
    #[SchemaProperty()]               public string $typeName = '';
    #[SchemaProperty()]               public int $targetPages = 0; // 0=采到站点 last_page
}
```

> `plan_step` 调用 `$plannerAgent->structured($userMessage, CrawlPlan::class)`，
> 产出即强类型对象；**Neuron 的 schema 声明同时充当 Validator 的第一道关卡**（缺失/越界字段在解析层即失败）。

### 5.3 CrawlJob（MySQL 新增表，见 §8）生命周期

```
draft → pending_approval → approved → running(部分/整体)
      → succeeded | partial | failed
      → (可) requeued / superseded
```

每个 Job 记录：plan_json、审批人/时间、关联的 series 快照、汇总统计、审计事件引用。
Workflow 的 Persistence 负责把流程状态与 Job 状态双向同步（进程重启可续）。

---

## 六、Engine 能力契约（= Neuron Tools 的后端）

Orchestrator 的 Agent 不直接 `exec` CLI，而是通过一组注册到 Neuron 的 **Tool** 调用 Engine 能力。
每个能力：语义、参数、**副作用级别（read | write）**、当前代码支撑度。

| 能力 | 语义（LLM 看到的描述） | 关键参数 | 副作用 | 现状支撑 |
| --- | --- | --- | --- | --- |
| `list_series` | 列出站点产品系列目录（供 LLM 做"模糊需求→真实 id"映射） | `query?`、`top` | read | ✅ `ApiClient::productTypes()` + `Db::source_types` 缓存 |
| `estimate_scope` | 预估某系列页数/行数，用于告知用户计划成本 | `type_ids[]` | read | ⚠️ 需小改：探测第 1 页读 `last_page`（现有接口已返回） |
| `seed_crawl` | 按系列播种采集（等价 `seed.php --types ... --max-pages ... [--force]`） | `type_ids[]`、`target_pages`、`force` | **write** | ⚠️ `Producer::run()` 直接可用；`target_pages` 需透传（改造点） |
| `get_progress` | 各系列游标/进度 | `type_ids[]?` | read | ✅ `RedisStore::getCursor`（`done_pages/total_pages/rows/status`） |
| `get_metrics` | 全局统计（等价 `stats.php` 数据） | — | read | ✅ `RedisStore::stats/streamLen/pendingTotal/deadLen` + `Db` 计数 |
| `list_results` | 查已落库型号（按系列/关键字/封装过滤，含 specs 样本） | `type_id?`、`model_like?`、`package?`、`limit` | read | ✅ `Db::modelsByType/recentModels`；需补过滤查询（改造点） |
| `list_dead` | 死信清单与原因 | `limit` | read | ⚠️ 需补 `RedisStore::readDead`（现状只有 `addDead`） |
| `requeue_dead` | 把死信/失败任务重新投递或降级处理 | `dead_ids[]?`、`type_id?` | **write** | ⚠️ 建议拆 `RedisStore::requeueDead` |
| `redo_series` | 强制重采某系列（等价 `reset + seed --force`） | `type_ids[]` | **write** | ✅ `resetState` + `Producer::run($ids, true)` |
| `control_workers` | 扩容/缩容 Worker（读消费组 consumer 数） | `desired` | **write** | ⚠️ 生产由 Supervisor 决定，演示可 CLI 起停，仅上报不改 |

能力与 Tool 之间放一层薄适配器，便于复用与单测：

```php
// src/agent/EngineTool.php —— 把 EngineCapability 包装成 Neuron Tool
class EngineTool extends NeuronAI\Tools\Tool
{
    public function __construct(private EngineCapability $cap) {}

    public function name(): string          { return $this->cap->name(); }
    public function description(): string   { return $this->cap->describe()['description']; }
    public function parameters(): array     { return $this->cap->describe()['parameters']; } // → LLM JSON Schema
    public function run(...$args): array    { return $this->cap->invoke($args); }            // read/write 统一入口
}
```

> read 工具：注册给 PlannerAgent，任意阶段可调。
> write 工具：**不注册进 Agent 的 `tools()`**，只在 Workflow 的 ⑤dispatch_step 由确定性代码调用 —— 从机制上杜绝"LLM 自己偷跑写操作"。

---

## 七、关键改造点清单（诚实标注"要动的代码"）

原则是**加层不改底**，但要让 Orchestrator 能表达"范围"，Engine 有几处最小扩展：

| # | 改造 | 涉及文件 | 改动量 | 说明 |
| --- | --- | --- | --- | --- |
| 1 | **任务级页数透传**：`target_pages` 随消息/游标下发，`Worker::process` 优先用它替代全局 `seedCfg['max_pages']` | `RedisStore::addTask`、游标 Hash 增加字段、`Worker::process` | 小 | 现状 `max_pages` 是进程级配置，无法表达"这个系列采到 last_page、那个只采 2 页" |
| 2 | **全量模式约定**：`target_pages=0` 即"采到 `last_page`"，`reachedEnd` 判定不再依赖 max_pages | `Worker::process` | 极小 | 现状 `$maxPages = seedCfg['max_pages'] ?? 3` 会截断真实末页 |
| 3 | **死信读取/回放 API**：补 `RedisStore::readDead($n)`、`requeueDead($ids)` | `RedisStore.php` | 小 | ReflectorAgent 需要 |
| 4 | **结果过滤查询**：补 `Db::searchModels($filters)` | `Db.php` | 小 | `list_results` 后端 |
| 5 | 新增 `agent` 配置段（LLM endpoint/key/model/预算/默认审批策略） | `config/config.php` | 极小 | 凭据走 `CW_*` 环境变量注入 |
| 6 | 新增 2 张编排表 + schema | `sql/schema.sql` | 小 | §8 DDL |
| 7 | Orchestrator 层（EngineTool / DTO / Neuron Agent / Workflow）+ HTTP API | `src/agent/*`、`bin/orchestrator.php`、`composer.json` | 中 | §9 |
| 8 | Workbench UI | `ui/` | 中 | §9 |

其余（Worker 消费语义、XCLAIM 接管、失败重试、Normalizer、幂等 upsert、代理池）**零改动**。

### 目标目录形态（新增部分）

```
crawl-worker-redis/
├─ composer.json                    # require neuron-core/neuron-ai
├─ bin/
│  ├─ (现有 init_db|seed|worker|stats|reset.php 不动)
│  └─ orchestrator.php              # 跑 CrawlRunWorkflow + serve(HTTP API)
├─ src/
│  ├─ (现有 Engine 类不动)
│  └─ agent/
│     ├─ EngineTool.php             # EngineCapability → Neuron Tool 适配器
│     ├─ Capabilities/*.php         # ListSeries/EstimateScope/SeedCrawl/GetProgress/...
│     ├─ DTO/CrawlPlan.php          # #[SchemaProperty] 强类型 Plan
│     ├─ Validator/PlanValidator.php# 纯代码：type_id∈真实目录/预算/风险
│     ├─ Neuron/PlannerAgent.php    # extends Agent（provider=tools=read）
│     ├─ Neuron/ReflectorAgent.php  # extends Agent（死信修补决策）
│     ├─ Workflow/CrawlRunWorkflow.php
│     └─ Http/Api.php               # /api/intents|jobs|approve|metrics|... 契约（§9.3）
├─ ui/                              # Workbench 前端（对接 Http/Api）
└─ sql/schema.sql                   # + crawl_jobs / crawl_agent_events
```

---

## 八、新增状态存储

沿用"结果进 MySQL、过程进 Redis"的分工，编排层状态进 MySQL（与 `source_types` 同级）：

```sql
-- 编排任务：一次"意图→计划"的长期记录（Workflow Persistence 的落点）
CREATE TABLE IF NOT EXISTS `crawl_jobs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`      VARCHAR(32)  NOT NULL,
  `session_id`  VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'UI 会话',
  `goal`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '用户原始意图',
  `plan_json`   JSON         NULL COMMENT 'CrawlPlan（含 items/budget/extract）',
  `status`      VARCHAR(24)  NOT NULL DEFAULT 'pending_approval'
                COMMENT 'draft/pending_approval/approved/running/succeeded/partial/failed',
  `risk`        VARCHAR(16)  NOT NULL DEFAULT 'write',
  `approved_by` VARCHAR(64)  NOT NULL DEFAULT '',
  `approved_at` DATETIME     NULL,
  `summary_json`JSON         NULL COMMENT '执行汇总：系列覆盖/行数/死信数',
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Orchestrator 编排任务';

-- 审计：每次 Tool/Workflow 调用的记录（可观测 + 防滥用 + Inspector 对照）
CREATE TABLE IF NOT EXISTS `crawl_agent_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`      BIGINT       NOT NULL DEFAULT 0,
  `tool`        VARCHAR(64)  NOT NULL,
  `params_json` JSON         NULL,
  `result_json` JSON         NULL COMMENT '摘要（不入全量数据）',
  `risk`        VARCHAR(16)  NOT NULL DEFAULT 'read',
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_job` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Agent 行为审计';
```

引擎过程态仍留在 Redis（`cur:*` 游标、`stats`、`tasks:dead`），不重复落库——进度由 Workflow 的 monitor_loop 拉取并写入 `crawl_jobs.summary_json` 做快照。

---

## 九、Orchestrator 与 UI 落地形态

### 9.1 部署形态 A：单机演示（最小闭环，全部 PHP）

```bash
composer require neuron-core/neuron-ai        # Agent Harness（要求 PHP ^8.1）
# 配置：CW_LLM_BASE_URL / CW_LLM_KEY / CW_LLM_MODEL（DeepSeek/OpenAI兼容网关/Ollama 均可）

php bin/orchestrator.php serve                # Workflow 服务：HTTP API + 对话
php bin/worker.php --idle-rounds 0            # Engine worker（Supervisor 或手动 1..N 个）
ui/index.html                                 # 纯前端，fetch Orchestrator HTTP API
```

- 不需要换语言、不需要跨服务：Orchestrator 进程内直接 `require src/bootstrap.php`，能力工具是本地对象调用。
- 实时进度：UI 轮询 `GET /api/jobs/{id}`（1s）；Neuron Streaming 用于对话打字机（可选）。

### 9.2 部署形态 B：生产解耦（推荐演进目标）

```
UI(静态, 任意托管) ──HTTP──► Orchestrator(常驻, Supervisor 守护)
                                   │  工具经同一进程封装调本地 Engine
                                   ▼
                          Engine：bin/worker.php ×N（Supervisor 管理，消费组自动均衡）
                          MySQL/Redis 双存储
                          观测：Inspector(Agent 时间线) + crawl_agent_events(审计)
```

### 9.3 Workbench UI → Orchestrator REST 契约

| Method & Path | 用途 | 对应 Workflow |
| --- | --- | --- |
| `POST /api/intents` | 提交意图 → 启动 CrawlRunWorkflow，返回 job(id, 待审批 Plan) | ①→④ |
| `GET  /api/series` | 系列目录（只读） | 工具 `list_series` |
| `GET  /api/jobs/{id}` | Job 详情：Plan、状态、进度、审批所需信息 | — |
| `POST /api/jobs/{id}/approve` / `/reject` | 审批/驳回 → 恢复被 Interruption 挂起的流程 | ④ |
| `GET  /api/jobs/{id}/events` | 审计事件流 | — |
| `GET  /api/metrics` | Engine 全局运行总览（= `stats.php`） | `get_metrics` |
| `GET  /api/results?type_id=&model_like=&limit=` | 结果查询 | `list_results` |
| `GET  /api/dead` + `POST /api/dead/requeue` | 死信清单 / 回放（人工或 ReflectorAgent） | ⑥ |

UI 四个视图：**会话栏**（意图与 Plan 草稿卡）、**审批卡**（写操作 + 预估范围，Approve/Reject）、**执行看板**（每个系列一张游标进度卡 + 日志）、**结果页**（表格式浏览/导出 CSV）。

---

## 十、防幻觉护栏与安全设计

LLM 在采集系统里最危险的不是"慢"，而是**一本正经地编造**。护栏四件套（Harness 提供机制，我们提供规则）：

1. **目录先验**：Planner 的 `tools()` 只有 read 工具（`list_series`/`estimate_scope`），拿到真实 `type_id→名称` 目录后才规划；`PlanValidator` 校验 `items[].type_id` 必须在目录集合内，否则打回重规划（≤2 次，仍失败转人工）。
2. **Schema 强校验（双层）**：Neuron **Structured Output** 在解析层消除格式问题；`PlanValidator`（普通 PHP）再校验业务约束（目录/预算/风险字段）。不依赖 LLM 自觉。
3. **写操作不可被 LLM 触发**：write 工具**不进 Agent `tools()`**，只由 Workflow ⑤/⑥的确定性代码在 **Interruption 审批通过后**调用。预算硬限由 Validator 截断，LLM 无权上调。
4. **审计可追溯**：每次工具调用写 `crawl_agent_events`，同时可接 Inspector 看 Agent 执行时间线，双轨对照。

另外把**不可由 Agent 修改的抓取纪律**留在 Engine 侧：`http.delay_ms`（礼貌抓取）、`claim_idle`（接管阈值）、`max_attempts` 不进工具参数表，Orchestrator 只能读不能改。

---

## 十一、演进路线

| 阶段 | 内容 | 效果 |
| --- | --- | --- |
| **P0 现状** | CLI 命令驱动 | 可靠但不智能 |
| **P1 能力网关** | §6 的 `EngineCapability` 工具化 + §7 表项 1~6，仍 CLI 驱动 | Engine 变成"可被程序调用"的工具集，任何上层都能接 |
| **P2 Harness 骨架** | 引入 Neuron v3，`CrawlRunWorkflow` 跑通 Job 状态机 + 确定性计划（规则匹配）+ **Interruption 审批流** | 不依赖 LLM 也能演示闭环，便于调试审批/持久化/审计 |
| **P3 LLM Orchestrator**（本文重点） | `PlannerAgent`/`ReflectorAgent` 接 Structured Output 与 read tools，人工审批闸门保留 | 一句话驱动全流程 |
| **P4 自主 + 多源** | 会话级预授权、预算配额自治、Reflector 自动修补（先观察再放开）；新增第二个站点只需新增 `ApiClient` + 对应 Capability 注册 | 平台化采集 |

> P3 之前不建议开放 Reflector 自动修补，先让"重试/换系列/改字段"的自动决策被人观察足够多次。

---

## 十二、端到端场景演练（验收剧本）

**用户在 UI 输入：**
> "把'低正向压降肖特基二极管'和'肖特基二极管'这两个系列采完整个分页，封装、PDF 都要；中间失败的重新自动跑一轮；完事后给我一份各系列型号数量汇总。"

**期望的 Orchestrator（Neuron Workflow）行为：**

1. `plan_step`：`PlannerAgent` 调 `list_series`（只读）→ 目录命中真实 id（如 `type_id=3` Low VF肖特基二极管、`type_id=1` 肖特基二极管）。
2. `structured()` 产出 `CrawlPlan`：`items=[{typeId:3,targetPages:0},{typeId:1,targetPages:0}]`、`keepRaw=true`、预算内、`risk=write`。
3. `PlanValidator`：id 均在目录 ✓、预算未超 ✓ → job `pending_approval`。
4. Workflow **Interruption** 挂起 → UI 弹计划卡 → 用户 Approve → 流程恢复。
5. `dispatch_step`：`seed_crawl(typeIds=[3,1], targetPages=0)` → 各系列投首页任务 → 现有 Worker 消费，按 `last_page` 自动翻页至末页（改造点 1、2 生效），失败按现有 `max_attempts`/`XCLAIM` 语义重试。
6. `monitor_loop`：轮询 `get_progress`/`get_metrics`；若 `dead>0` → 分支到 `ReflectorAgent` 生成"重投死信"修补（write，走会话预授权或再次审批）→ 回 `requeue_dead`。
7. `report_step`：写 `summary_json`（type_id/覆盖页数/行数/dead 数），UI 结果页渲染汇总 + `list_results` 表格，一键导出 CSV。

---

## 十三、与现有文档/方案的关系

- 《多进程高并发解决方案》：Engine 层进程编排（Supervisor），Agent 化后 **Worker 进程仍由它管理**，Workflow 只读消费组状态（`control_workers` 建议只上报不改）。
- 《代理 IP 池方案》：Engine 层反爬基础设施，`http.proxy` 属于"Agent 不可改的抓取纪律"（§10），只通过 `get_metrics` 暴露代理健康告警给 Reflector。
- `architecture.html` / `crawl-flow.html`：可另出一页 Agent 化拓扑，本文是它的内容底稿。

> 一句话总结：**Engine 提供"被信任的执行"，Neuron v3 Harness 提供"可工程化的编排"，LLM 提供"可校验的规划"，UI 提供"可观察的对话"；
> LLM 的想象力被关在 Structured Output + 审批闸门的笼子里，Engine 的可靠性一点也不让步。**
