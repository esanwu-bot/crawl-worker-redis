// Package adapter 提供数据源适配器的注册与装配。
//
// Task 载荷里的 source 决定用哪个 Adapter；Worker / Producer 通过注册表取适配器，
// 这是 Runtime 保持“零领域分支”的唯一入口。
package adapter

import (
	"fmt"
	"sort"

	"crawlkit/internal/domain/source"
)

// Registry 适配器路由注册表。
type Registry struct {
	adapters map[string]source.Adapter
}

// NewRegistry 创建空注册表。
func NewRegistry() *Registry {
	return &Registry{adapters: map[string]source.Adapter{}}
}

// Register 注册适配器；name 为空时用 adapter.Source()。
func (r *Registry) Register(a source.Adapter, name string) {
	if name == "" {
		name = a.Source()
	}
	r.adapters[name] = a
}

// Has 判断数据源是否已注册。
func (r *Registry) Has(name string) bool {
	_, ok := r.adapters[name]
	return ok
}

// Get 取适配器。
func (r *Registry) Get(name string) (source.Adapter, error) {
	a, ok := r.adapters[name]
	if !ok {
		return nil, fmt.Errorf("未注册的数据源: %s", name)
	}
	return a, nil
}

// Sources 返回已注册的数据源名（有序）。
func (r *Registry) Sources() []string {
	out := make([]string, 0, len(r.adapters))
	for k := range r.adapters {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

// All 返回全部适配器。
func (r *Registry) All() map[string]source.Adapter { return r.adapters }
