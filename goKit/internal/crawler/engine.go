// Package crawler 是采集引擎的 Runtime：
//
//	Producer（播种） -> Redis Stream -> Worker（消费/接管） -> Adapter -> Normalizer -> MySQL
//
// 该层只依赖 domain 契约与基础设施接口，对具体数据源零感知。
package crawler

import (
	"context"
	"fmt"

	"crawlkit/internal/adapter"
	"crawlkit/internal/config"
	"crawlkit/internal/infrastructure/httpx"
	"crawlkit/internal/infrastructure/mysqlstore"
	"crawlkit/internal/infrastructure/observability"
	"crawlkit/internal/infrastructure/proxy"
	"crawlkit/internal/infrastructure/redisstore"
)

// Engine 汇总一次运行所需的全部组件。
type Engine struct {
	Cfg      *config.Config
	Log      *observability.Logger
	Store    *redisstore.Store
	DB       *mysqlstore.Store
	Adapters *adapter.Registry
	Client   *httpx.Client
	Pool     *proxy.Pool
}

// New 依据配置装配引擎（连接 Redis/MySQL、构建代理池、注册适配器、自动建表）。
func New(ctx context.Context, cfg *config.Config, logDir string) (*Engine, error) {
	log := observability.New(logDir, "crawler-engine")

	store, err := redisstore.New(ctx, cfg.Redis, redisstore.TaskConfig{
		Stream:     cfg.Task.Stream,
		Group:      cfg.Task.Group,
		DeadStream: cfg.Task.DeadStream,
		StatsKey:   cfg.Task.StatsKey,
		MaxLen:     5000,
	})
	if err != nil {
		return nil, err
	}

	db, err := mysqlstore.Open(ctx, cfg.MySQL)
	if err != nil {
		_ = store.Close()
		return nil, err
	}

	var pool *proxy.Pool
	if cfg.HTTP.Proxy.Enabled {
		pool = proxy.New(cfg.HTTP.Proxy.List, proxy.Config{
			Mode:           cfg.HTTP.Proxy.Mode,
			Cooldown:       durationMS(cfg.HTTP.Proxy.CooldownMS),
			DropAfterFails: cfg.HTTP.Proxy.DropAfterFails,
			HashKey:        cfg.HTTP.Proxy.Key,
		}, store.Client(), store.Prefix())
	}

	client := httpx.New(cfg.HTTP, pool)

	reg, err := adapter.FromConfig(cfg, client)
	if err != nil {
		_ = db.Close()
		_ = store.Close()
		return nil, fmt.Errorf("装配数据源适配器失败: %w", err)
	}

	eng := &Engine{
		Cfg:      cfg,
		Log:      log,
		Store:    store,
		DB:       db,
		Adapters: reg,
		Client:   client,
		Pool:     pool,
	}
	// 把启动配置播种进 sources 表，并按 DB 的启用状态重建适配器，
	// 使后台创建/停用的数据源无需改 config 即可生效。
	if err := eng.SyncSources(ctx); err != nil {
		log.Warn("同步配置式数据源失败（继续使用 config 内数据源）: %v", err)
	}
	return eng, nil
}

// SyncSources 把 config.sources 播种进 sources 表（仅缺失时写入），再按 DB 状态重载适配器。
func (e *Engine) SyncSources(ctx context.Context) error {
	for name, sc := range e.Cfg.Sources {
		if err := e.DB.SeedSource(ctx, adapter.DefinitionFromConfig(name, sc)); err != nil {
			return fmt.Errorf("播种数据源 %s 失败: %w", name, err)
		}
	}
	return e.ReloadSources(ctx)
}

// ReloadSources 从 sources 表重新装配适配器：启用的注册，停用的移除。
func (e *Engine) ReloadSources(ctx context.Context) error {
	list, err := e.DB.ListSources(ctx)
	if err != nil {
		return err
	}
	for _, d := range list {
		if !d.Enabled() {
			e.Adapters.Unregister(d.ID)
			continue
		}
		a, err := adapter.BuildFromDefinition(d, e.Client)
		if err != nil {
			e.Log.Warn("跳过数据源 %s: %v", d.ID, err)
			e.Adapters.Unregister(d.ID)
			continue
		}
		e.Adapters.Register(a, d.ID)
	}
	return nil
}

// Close 释放资源。
func (e *Engine) Close() {
	if e.DB != nil {
		_ = e.DB.Close()
	}
	if e.Store != nil {
		_ = e.Store.Close()
	}
	if e.Log != nil {
		e.Log.Close()
	}
}

// Producer 返回播种器。
func (e *Engine) Producer() *Producer {
	return &Producer{cfg: e.Cfg, store: e.Store, db: e.DB, adapters: e.Adapters, log: e.Log}
}

// Worker 返回消费者。
func (e *Engine) Worker(consumer string) *Worker {
	return &Worker{cfg: e.Cfg, store: e.Store, db: e.DB, adapters: e.Adapters, log: e.Log, consumer: consumer}
}
