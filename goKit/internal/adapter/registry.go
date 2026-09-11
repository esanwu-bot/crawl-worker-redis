// Package adapter 提供数据源适配器的注册与装配。
//
// Task 载荷里的 source 决定用哪个 Adapter；Worker / Producer 通过注册表取适配器，
// 这是 Runtime 保持“零领域分支”的唯一入口。
//
// 注册表支持运行时增删（配置式数据源在后台创建后无需重启即可生效），因此内部加锁。
package adapter

import (
	"fmt"
	"sort"
	"sync"

	"crawlkit/internal/domain/source"
)

// Registry 适配器路由注册表（并发安全）。
type Registry struct {
	mu       sync.RWMutex
	adapters map[string]source.Adapter
}

// NewRegistry 创建空注册表。
func NewRegistry() *Registry {
	return &Registry{adapters: map[string]source.Adapter{}}
}

// Register 注册适配器；name 为空时用 adapter.Source()。
func (r *Registry) Register(a source.Adapter, name string) {
	if a == nil {
		return
	}
	if name == "" {
		name = a.Source()
	}
	r.mu.Lock()
	r.adapters[name] = a
	r.mu.Unlock()
}

// Unregister 移除适配器。
func (r *Registry) Unregister(name string) {
	r.mu.Lock()
	delete(r.adapters, name)
	r.mu.Unlock()
}

// Has 判断数据源是否已注册。
func (r *Registry) Has(name string) bool {
	r.mu.RLock()
	_, ok := r.adapters[name]
	r.mu.RUnlock()
	return ok
}

// Get 取适配器。
func (r *Registry) Get(name string) (source.Adapter, error) {
	r.mu.RLock()
	a, ok := r.adapters[name]
	r.mu.RUnlock()
	if !ok {
		return nil, fmt.Errorf("未注册的数据源: %s", name)
	}
	return a, nil
}

// Sources 返回已注册的数据源名（有序）。
func (r *Registry) Sources() []string {
	r.mu.RLock()
	out := make([]string, 0, len(r.adapters))
	for k := range r.adapters {
		out = append(out, k)
	}
	r.mu.RUnlock()
	sort.Strings(out)
	return out
}

// All 返回全部适配器的快照副本。
func (r *Registry) All() map[string]source.Adapter {
	r.mu.RLock()
	out := make(map[string]source.Adapter, len(r.adapters))
	for k, v := range r.adapters {
		out[k] = v
	}
	r.mu.RUnlock()
	return out
}
