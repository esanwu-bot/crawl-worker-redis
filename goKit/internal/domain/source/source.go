// Package source 定义数据源适配器契约（Runtime 与 Adapter 的边界）。
//
// Runtime（Worker / Producer / RedisStore）只依赖本契约与 task 契约，
// 对数据源的具体协议、字段、分页语义完全无感知 —— 增长只发生在 Adapter 层。
package source

import (
	"context"

	"crawlkit/internal/domain/record"
	"crawlkit/internal/domain/task"
)

// UnitSink 供 Adapter 在发现阶段沉淀采集单元目录（写入 source_types）。
type UnitSink interface {
	UpsertSourceType(ctx context.Context, source string, typeID int, cnName, enName string) error
}

// ListResult 是一次列表页抓取的结果。
type ListResult struct {
	Page     int
	LastPage int
	Ended    bool
	Records  []record.Canonical
}

// Adapter 是数据源适配器契约。
//
// 一个 Adapter 把“连接 + 抽取 + 归一化”收敛在自己内部，对外只产出 Canonical Record；
// 发现阶段的目录元数据可沉淀到 source_types，但返回值仅需单元列表。
type Adapter interface {
	// Source 返回数据源标识，与 task.source / crawl_jobs.source 一致。
	Source() string

	// Discover 发现采集单元（播种阶段的“目录”）。
	Discover(ctx context.Context, sink UnitSink) ([]task.Unit, error)

	// ExecuteList 执行一次“列表页”任务。
	ExecuteList(ctx context.Context, t *task.Payload) (*ListResult, error)

	// HealthCheck 探测数据源可用性（供 API/运维使用）。
	HealthCheck(ctx context.Context) error
}
