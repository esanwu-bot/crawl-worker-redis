# Crawler Engine (Go) — 常用构建与运行命令
.DEFAULT_GOAL := help
GO        ?= go
BIN_DIR   ?= bin
CONFIG    ?= configs/config.yaml
LDFLAGS   ?= -s -w

CMDS := crawler-api crawler-worker crawler-seed crawler-scheduler crawler-cli

.PHONY: help
help: ## 显示帮助
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

.PHONY: tidy
tidy: ## 整理依赖
	$(GO) mod tidy

.PHONY: fmt
fmt: ## 格式化代码
	gofmt -w .

.PHONY: vet
vet: ## 静态检查
	$(GO) vet ./...

.PHONY: test
test: ## 运行单元测试
	$(GO) test ./...

.PHONY: build
build: ## 构建全部命令到 bin/
	@mkdir -p $(BIN_DIR)
	@for c in $(CMDS); do \
		echo ">> building $$c"; \
		$(GO) build -trimpath -ldflags "$(LDFLAGS)" -o $(BIN_DIR)/$$c ./cmd/$$c || exit 1; \
	done

.PHONY: run-api
run-api: ## 启动 API 服务
	$(GO) run ./cmd/crawler-api -config $(CONFIG)

.PHONY: run-worker
run-worker: ## 启动一个 Worker
	$(GO) run ./cmd/crawler-worker -config $(CONFIG)

.PHONY: seed
seed: ## 播种（默认数据源）
	$(GO) run ./cmd/crawler-seed -config $(CONFIG)

.PHONY: seed-maccms
seed-maccms: ## 播种 MacCMS（内置示例分类）
	$(GO) run ./cmd/crawler-seed -config $(CONFIG) -source maccms -max-pages 2

.PHONY: initdb
initdb: ## 建库建表
	$(GO) run ./cmd/crawler-cli -config $(CONFIG) initdb

.PHONY: stats
stats: ## 查看运行统计
	$(GO) run ./cmd/crawler-cli -config $(CONFIG) stats

.PHONY: dead
dead: ## 查看死信
	$(GO) run ./cmd/crawler-cli -config $(CONFIG) dead

.PHONY: requeue
requeue: ## 重投死信
	$(GO) run ./cmd/crawler-cli -config $(CONFIG) requeue

.PHONY: reset
reset: ## 清空 Redis 任务状态（危险）
	$(GO) run ./cmd/crawler-cli -config $(CONFIG) reset --yes

.PHONY: clean
clean: ## 清理构建产物
	rm -rf $(BIN_DIR)
