package crawler

import (
	"context"
	"fmt"

	"crawlkit/internal/adapter"
	"crawlkit/internal/config"
	"crawlkit/internal/domain/job"
	"crawlkit/internal/domain/source"
	"crawlkit/internal/domain/task"
	"crawlkit/internal/infrastructure/mysqlstore"
	"crawlkit/internal/infrastructure/observability"
	"crawlkit/internal/infrastructure/redisstore"
)

// Producer 播种器：发现采集单元 -> 建作业 -> 初始化游标 -> 投递首页任务。
type Producer struct {
	cfg      *config.Config
	store    *redisstore.Store
	db       *mysqlstore.Store
	adapters *adapter.Registry
	log      *observability.Logger
}

// SeedResult 播种结果摘要。
type SeedResult struct {
	JobID  string `json:"job_id"`
	Source string `json:"source"`
	Units  int    `json:"units"`
	Queued int    `json:"queued"`
}

// Seed 执行一次播种。
//
//	unitIDs  : 指定采集单元；为空则调用 Adapter.Discover 自动发现
//	force    : 清理追加锁并重建游标（用于重采）
//	maxPages : 目标页上限（0 => 用 config.seed.max_pages）
func (p *Producer) Seed(ctx context.Context, sourceName string, unitIDs []string, force bool, maxPages int) (*SeedResult, error) {
	if sourceName == "" {
		sourceName = p.cfg.DefaultSource
	}
	a, err := p.adapters.Get(sourceName)
	if err != nil {
		return nil, err
	}
	if maxPages <= 0 {
		maxPages = p.cfg.Seed.MaxPages
	}
	entity := p.entityOf(sourceName)

	units, err := p.resolveUnits(ctx, sourceName, entity, a, unitIDs)
	if err != nil {
		return nil, err
	}
	if len(units) == 0 {
		return nil, fmt.Errorf("数据源 %s 未发现任何采集单元（可用 --units 显式指定）", sourceName)
	}

	scope := make([]job.ScopeItem, 0, len(units))
	for _, u := range units {
		scope = append(scope, job.ScopeItem{Entity: u.Entity, UnitID: u.UnitID, UnitName: u.UnitName})
	}
	jobID, err := p.db.CreateJob(ctx, sourceName, scope)
	if err != nil {
		return nil, fmt.Errorf("创建作业失败: %w", err)
	}

	queued := 0
	for _, u := range units {
		if u.Entity == "" {
			u.Entity = entity
		}
		t := task.Home(u, sourceName, 1, maxPages, jobID)
		cursorKey := t.CursorKey()

		if force {
			p.store.ClearTaskLocks(ctx, cursorKey)
		}
		_ = p.store.InitCursor(ctx, cursorKey, map[string]any{
			"source":    sourceName,
			"entity":    u.Entity,
			"unit_id":   u.UnitID,
			"unit_name": u.UnitName,
		})

		if _, err := p.store.PushTask(ctx, t); err != nil {
			p.log.Error("投递任务失败 %s: %v", t.Describe(), err)
			continue
		}
		queued++
	}
	p.store.StatIncr(ctx, "seed_units", int64(len(units)))
	p.store.StatIncr(ctx, "seed_tasks", int64(queued))
	p.log.Info("播种完成 job=%s source=%s units=%d queued=%d max_pages=%d",
		jobID, sourceName, len(units), queued, maxPages)

	return &SeedResult{JobID: jobID, Source: sourceName, Units: len(units), Queued: queued}, nil
}

// resolveUnits 解析采集单元。
//
//   - unitIDs 为空：调用 Adapter.Discover 自动发现目录，并按 limit 截断
//   - unitIDs 非空：以指定 id 为准，名称优先从 source_types 目录回填
func (p *Producer) resolveUnits(ctx context.Context, sourceName, entity string, a source.Adapter, unitIDs []string) ([]task.Unit, error) {
	if len(unitIDs) == 0 {
		units, err := a.Discover(ctx, p.db)
		if err != nil {
			return nil, fmt.Errorf("发现采集单元失败: %w", err)
		}
		if len(units) > p.cfg.Seed.Limit {
			units = units[:p.cfg.Seed.Limit]
		}
		for i := range units {
			if units[i].Entity == "" {
				units[i].Entity = entity
			}
		}
		return units, nil
	}

	names := p.unitNames(ctx, sourceName)
	units := make([]task.Unit, 0, len(unitIDs))
	for _, id := range unitIDs {
		if id == "" {
			continue
		}
		units = append(units, task.Unit{
			Entity:   entity,
			UnitID:   id,
			UnitName: names[id],
		})
	}
	return units, nil
}

// unitNames 从 source_types 目录读取 unit_id -> name 映射。
func (p *Producer) unitNames(ctx context.Context, sourceName string) map[string]string {
	out := map[string]string{}
	rows, err := p.db.ListSourceTypes(ctx, sourceName)
	if err != nil {
		return out
	}
	for _, r := range rows {
		name := r.CnName
		if name == "" {
			name = r.EnName
		}
		out[fmt.Sprint(r.TypeID)] = name
	}
	return out
}

func (p *Producer) entityOf(sourceName string) string {
	if sc, ok := p.cfg.Sources[sourceName]; ok && sc.Entity != "" {
		return sc.Entity
	}
	return "item"
}
