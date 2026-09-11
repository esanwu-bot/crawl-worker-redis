# Crawler Console (Workbench)

> 数据采集管理平台的**控制面**前端，消费 `goKit` 的 `crawler-api`（`/api/v1`）。
> 对应 `后台方案.md` 的 Phase 1 MVP：仪表盘 / 采集任务 / 任务详情 / 数据源 / 采集结果 / 死信。

## 技术栈

- Vue 3 + TypeScript + Vite
- Ant Design Vue 4（Vben 同款 UI 基座）
- Vue Router 4 + Pinia
- ECharts（仪表盘图表）
- Axios（REST 客户端）

## 快速开始

```bash
cd apps/web
npm install

# 先启动采集引擎 API（另开终端）
#   cd goKit && go run ./cmd/crawler-api          # 默认 :8088
npm run dev                                        # http://localhost:5173
```

开发期 Vite 通过 `/api` 代理到 `VITE_API_TARGET`（默认 `http://localhost:8088`），无需处理跨域。

## 构建

```bash
npm run build      # 类型检查 + 产物到 dist/
npm run preview
```

生产部署时把 `dist/` 交给 Nginx 托管，并将 `/api` 反向代理到 `crawler-api`。

## 页面与后端接口对照

| 页面 | 路由 | 后端接口 |
| --- | --- | --- |
| 仪表盘 | `/dashboard` | `GET /metrics`、`GET /jobs`、`GET /sources` |
| 采集任务 | `/jobs` | `GET /jobs`、`POST /jobs`、`POST /jobs/{id}/{pause,resume,cancel}` |
| 任务详情 | `/jobs/:id` | `GET /jobs/{id}` |
| 数据源 | `/sources` | `GET/POST/PUT/DELETE /sources`、`POST /sources/{id}/{test,discover,enable,disable}`（`GET /sources?probe=1` 探测） |
| Schema 编辑器 | `/sources/:id/schema` | `GET/POST /sources/{id}/schema`、`PUT/DELETE /schemas/{id}`、`POST /schemas/{id}/{test,publish}` |
| 采集结果 | `/records` | `GET /results` |
| 死信队列 | `/dead-letters` | `GET /dead`、`POST /dead/requeue` |
| Worker / Redis | `/workers` | `GET /metrics` |

## 后续（对应 `后台方案.md` 的 Phase 3/4）

- Dead Letter `retry` / `ignore` / `repair`
- Worker 心跳与实例级 metrics（Runtime 上报后）
- 调度器（Cron / 增量）
