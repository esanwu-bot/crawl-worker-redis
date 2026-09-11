package adapter

import (
	"fmt"

	"crawlkit/internal/adapter/maccms"
	"crawlkit/internal/adapter/shikues"
	"crawlkit/internal/config"
	"crawlkit/internal/domain/source"
	"crawlkit/internal/infrastructure/httpx"
)

// FromConfig 依据 config.sources 构建注册表（Source Definition 驱动）。
func FromConfig(cfg *config.Config, client *httpx.Client) (*Registry, error) {
	if len(cfg.Sources) == 0 {
		return nil, fmt.Errorf("config.sources 为空，未配置任何数据源")
	}
	reg := NewRegistry()
	for name, sc := range cfg.Sources {
		a, err := Build(name, sc, client)
		if err != nil {
			return nil, err
		}
		reg.Register(a, name)
	}
	return reg, nil
}

// Build 依据单个 SourceConfig 构造适配器。
//
// 新增 connector 类型只需在此分派；运行时创建的配置式数据源也复用本函数。
func Build(name string, sc config.SourceConfig, client *httpx.Client) (source.Adapter, error) {
	kind := sc.Adapter
	if kind == "" {
		kind = name
	}
	switch kind {
	case source.AdapterShikues:
		return shikues.New(client, sc.APIBase, name, sc.Entity, sc.Type), nil
	case source.AdapterMacCMS:
		return maccms.New(client, maccms.Config{
			Source:    name,
			Site:      sc.Site,
			APIBase:   sc.APIBase,
			Entity:    sc.Entity,
			PageSize:  sc.PageSize,
			DetailURL: sc.DetailURL,
			Units:     toUnitDefs(sc.Units),
		}), nil
	default:
		return nil, fmt.Errorf("未知 adapter 类型: %s（source=%s）", kind, name)
	}
}

// BuildFromDefinition 依据配置式数据源定义构造适配器。
func BuildFromDefinition(d source.Definition, client *httpx.Client) (source.Adapter, error) {
	d.Normalize()
	return Build(d.ID, DefinitionToConfig(d), client)
}

// DefinitionToConfig 把后台管理的 Source Definition 还原为 Runtime 的 SourceConfig。
func DefinitionToConfig(d source.Definition) config.SourceConfig {
	units := make([]config.UnitConfig, 0, len(d.Units))
	for _, u := range d.Units {
		units = append(units, config.UnitConfig{
			UnitID:   config.FlexString(u.UnitID),
			UnitName: u.UnitName,
			Params:   u.Params,
		})
	}
	return config.SourceConfig{
		Adapter:   d.Adapter,
		Site:      d.Site,
		APIBase:   d.APIBase,
		Type:      d.Type,
		Entity:    d.Entity,
		PageSize:  d.PageSize,
		DetailURL: d.DetailURL,
		Units:     units,
	}
}

// DefinitionFromConfig 把启动配置还原为可入库的 Source Definition（启动播种用）。
func DefinitionFromConfig(name string, sc config.SourceConfig) source.Definition {
	units := make([]source.UnitDefinition, 0, len(sc.Units))
	for _, u := range sc.Units {
		units = append(units, source.UnitDefinition{
			UnitID:   string(u.UnitID),
			UnitName: u.UnitName,
			Params:   u.Params,
		})
	}
	return source.Definition{
		ID:        name,
		Name:      name,
		Adapter:   sc.Adapter,
		Entity:    sc.Entity,
		Site:      sc.Site,
		APIBase:   sc.APIBase,
		Type:      sc.Type,
		PageSize:  sc.PageSize,
		DetailURL: sc.DetailURL,
		Status:    source.StatusEnabled,
		Units:     units,
	}
}

func toUnitDefs(in []config.UnitConfig) []maccms.UnitDef {
	out := make([]maccms.UnitDef, 0, len(in))
	for _, u := range in {
		out = append(out, maccms.UnitDef{
			UnitID:   string(u.UnitID),
			UnitName: u.UnitName,
			Params:   u.Params,
		})
	}
	return out
}
