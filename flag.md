# FLAG — 项目趋势声明

> 定位变更：把 `crawl-worker-redis` 从「Shikues 站点采集器」升级为 **「与数据源无关的可靠采集 Runtime」**。
> 记录于 2026-09-09，衔接 `agentVersion/handoff.md`（P3 Neuron 已就绪）。

---

## 1. 一句话方向

> 不要把它泛化成"支持很多网站的爬虫"，而应泛化成 **"与数据源无关的可靠采集执行引擎"**。
> Shikues、MacCMS、WordPress、Shopify 都只是 **Source Adapter**；
> Redis Stream / Consumer Group / PEL / XCLAIM / Retry / Dead Letter / 幂等 Upsert 是 **通用 Runtime**（已被 README 验证，不重写）。

## 2. 认知约束（避免踩的坑）

❌ 不要长出第二个 `shikues` 全家桶：

```text
ApiClient.php / MacCmsApiClient.php / ShopifyApiClient.php ...   ❌
Normalizer.php / MacCmsNormalizer.php / ShopifyNormalizer.php ... ❌
```

✅ 增长点只允许出现在 **Adapter 层**：

```text
                          Crawl Platform
              ┌───────────────┴────────────────┐
        Source Adapter                     Crawl Runtime
    ┌───────┼────────┐                          │
  Shikues  MacCMS  Generic...            Redis Stream / CG / PEL
  Adapter  Adapter  Adapter              Retry / DLQ / Worker
    └───────┼────────┘                          │
            ▼                                   ▼
     Canonical Record                 Idempotent Upsert
            ▼                                   │
        Data Sink (MySQL / JSON / ES / API) ◄───┘
```

## 3. 六个核心抽象（V1 契约）

| # | 抽象 | 职责 | 关键点 |
|---|---|---|---|
| 1 | **Source** | 定义"采什么"（domain/entity） | 只声明不实现协议 |
| 2 | **Connector** | 定义"怎么访问"（协议+鉴权+分页参数） | `JsonApi/XmlApi/Html/Sitemap/Db` |
| 3 | **Extractor** | 从原始 Response 定位 list/pagination/detail | JSONPath / CSS / XPath |
| 4 | **Normalizer** | Source Record → Canonical Record | 收敛、清洗、去重键 |
| 5 | **Task** | 一个可独立消费的游标单元 | `source+entity+operation+cursor+params` |
| 6 | **Sink** | 幂等落库/导出 | MySQL / JSON / ES |

Worker 执行模型（**对数据源无感知**）：

```text
Task → Connector → Raw Response → Extractor → Raw Record → Normalizer → Canonical Record → Sink
```

## 4. Task 必须彻底泛化（Redis Stream 根本不关心你采什么）

现在的 `type_id + page` 是元器件领域特化的，应演进为统一载荷：

```json
{
  "job_id": "job_xxx",
  "source": "maccms",
  "entity": "vod",
  "operation": "list",
  "cursor": { "page": 3 },
  "params": { "t": 6 }
}
```

Shikues 与 MacCMS 只是 cursor/params 语义不同，执行模型与现在 `series → page1/2/3` **完全相同**——这就是"Redis Worker 不用重写"的证明。

## 5. 记录规范：Canonical + Domain Payload + Raw，不做万能表

每类记录三件套一起落库：

```json
{
  "source": "maccms",
  "entity": "vod",
  "external_id": "12345",
  "title": "xxx",
  "url": "...", "image": "...",
  "published_at": "...", "updated_at": "...",
  "payload": { "vod_name": "...", "vod_year": "2026", "vod_actor": "...", "vod_play_url": "..." },
  "raw": {}
}
```

教训：很多"通用采集平台"死于把所有数据强塞一张万能表；保留领域 payload + raw 以便按 schema 检索。

## 6. 数据源"从代码变成配置"（Source Definition）

阶段目标：YAML/JSON 定义 Source，代码零新增即可接入新站：

```yaml
source:
  id: maccms-demo
  type: maccms
  connector: json_api
endpoint:
  base_url: https://example.com
  path: /api.php/provide/vod/
entities:
  vod:
    list: { method: GET, params: { ac: list, pg: "{{page}}", pagesize: 20 } }
    pagination: { page: "$.page", page_count: "$.pagecount", total: "$.total", items: "$.list" }
mapping:
  external_id: "$.vod_id"
  title: "$.vod_name"
  image: "$.vod_pic"
  ...
```

## 7. 目录演进目标

```text
src/
├── Core/         CrawlJob CrawlTask CrawlContext CrawlResult Pagination
├── Contract/     Source Connector Extractor Normalizer Sink TaskPlanner 接口
├── Connector/    Http JsonApi XmlApi Html Sitemap
├── Source/       Shikues/  MacCMS/   (每域一个目录，各自 Source/Extractor/Normalizer)
├── Runtime/      Worker Producer RedisStore RetryPolicy
├── Sink/         MySqlSink JsonSink SearchSink
├── Agent/        (agentVersion 的 Neuron 层收敛于此) Agents Tools Workflow Planner Memory
└── Infrastructure/ Db Http Logger ProxyPool
```

`agentVersion/`（Agent Harness 原型）与根 `src/`（Runtime 原型）后续向此结构收敛。

## 8. 数据库演进（现在只有 source_types + product_models）

```text
sources / source_entities / source_schemas / source_fields
crawl_jobs / crawl_tasks / crawl_task_attempts
crawl_records / crawl_record_versions
datasets / dataset_records
agent_runs / agent_tool_calls / agent_events
dead_letters / repair_runs
```

关系：`Source → Entity → Schema`；`Crawl Job → Task → Attempts → Records`。让所有领域共享模型。

## 9. 产品路线（克制、按风险递增）

| 阶段 | 内容 |
|---|---|
| **V1** | Generic JSON API + **MacCMS Adapter** + Shikues Adapter |
| V1.5 | XML API、Sitemap、RSS |
| V2 | Generic HTML、CSS Selector、XPath |
| V3 | Browser / Playwright / JS 渲染 / 登录态 |
| V4 | AI Source Discovery / Schema Discovery / Mapping / Repair |

V1 的 MacCMS 甚至不需要 HTML 爬虫——`/api.php/provide/vod/?ac=list|detail&t&pg&ids&h` 官方标准化接口即可闭环验证多源架构。

## 10. Neuron 在其中的角色（V1 就位）

```text
User Goal → Research Agent（检测站点 /api.php / JSON → 识别 MacCMS）
         → Source Discovery → 加载 Adapter → Schema Discovery
         → 生成 Crawl Plan → Human Approval → Create Job → Redis Stream → Worker
```

对应落地：Neuron 负责**发现数据源、理解 Schema、生成采集计划、处理异常**；当前 P3 的 `NeuronPlanner` 只是这个能力的第一个消费者。

## 11. 首个里程碑（下一步直接落代码）

> **抽 `SourceAdapterInterface`，同时实现 `ShikuesAdapter + MacCmsAdapter`，用两个业务域完全不同的 Adapter 验证整套通用采集协议。**

验收标准：两套 Adapter 跑同一批 Redis Worker / 同一套 Job-Task 模型，`crawl_jobs` 里 `source` 字段只差一个值，Runtime 代码零领域分支。

## 12. 参考

- 设计总纲：`Agent化设计-CrawlerEngine-Orchestrator-Workbench.md`
- 阶段收尾：`agentVersion/handoff.md`（P3 Neuron v3 规划节点已接入并实测通过）
- 数据源形态：Shikues（电子元器件，Series→Model）、MacCMS（影视，Category→VOD，支持 `h` 增量参数）
