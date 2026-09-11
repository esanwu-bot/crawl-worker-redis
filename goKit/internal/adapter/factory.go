package adapter

import (
	"fmt"

	"crawlkit/internal/adapter/maccms"
	"crawlkit/internal/adapter/shikues"
	"crawlkit/internal/config"
	"crawlkit/internal/infrastructure/httpx"
)

// FromConfig 依据 config.sources 构建注册表（Source Definition 驱动）。
//
// 新增数据源时只需在 configs/config.yaml 增加一个配置段并在本工厂注册分派逻辑
// （→ 演进方向：纯配置驱动，见 README 的 Adapter 扩展章节）。
func FromConfig(cfg *config.Config, client *httpx.Client) (*Registry, error) {
	if len(cfg.Sources) == 0 {
		return nil, fmt.Errorf("config.sources 为空，未配置任何数据源")
	}
	reg := NewRegistry()
	for name, sc := range cfg.Sources {
		kind := sc.Adapter
		if kind == "" {
			kind = name
		}
		switch kind {
		case "shikues":
			reg.Register(shikues.New(client, sc.APIBase, name, sc.Entity, sc.Type), name)
		case "maccms":
			reg.Register(maccms.New(client, maccms.Config{
				Source:    name,
				Site:      sc.Site,
				APIBase:   sc.APIBase,
				Entity:    sc.Entity,
				PageSize:  sc.PageSize,
				DetailURL: sc.DetailURL,
				Units:     toUnitDefs(sc.Units),
			}), name)
		default:
			return nil, fmt.Errorf("未知 adapter 类型: %s（source=%s）", kind, name)
		}
	}
	return reg, nil
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
