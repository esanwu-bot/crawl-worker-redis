package source

import (
	"fmt"
	"strings"
	"time"
)

// 数据源状态。
const (
	StatusEnabled  = "enabled"
	StatusDisabled = "disabled"
)

// 内置 connector/adapter 类型。
const (
	AdapterShikues = "shikues"
	AdapterMacCMS  = "maccms"
)

// UnitDefinition 是 Source Definition 中静态声明的采集单元。
type UnitDefinition struct {
	UnitID   string         `json:"unit_id"`
	UnitName string         `json:"unit_name"`
	Params   map[string]any `json:"params,omitempty"`
}

// Definition 是配置式数据源（Source Definition），对应 sources 表一行。
//
// 它把原本写死在 config.yaml / 代码里的连接信息与采集单元，变成后台可管理的对象。
type Definition struct {
	ID        string           `json:"id"`
	Name      string           `json:"name"`
	Adapter   string           `json:"adapter"`
	Entity    string           `json:"entity"`
	Site      string           `json:"site"`
	APIBase   string           `json:"api_base"`
	Type      int              `json:"type,omitempty"`
	PageSize  int              `json:"page_size"`
	DetailURL string           `json:"detail_url"`
	Status    string           `json:"status"`
	Units     []UnitDefinition `json:"units,omitempty"`
	CreatedAt time.Time        `json:"created_at"`
	UpdatedAt time.Time        `json:"updated_at"`
}

// Normalize 补齐零值，保证入库与下游可用。
func (d *Definition) Normalize() {
	d.ID = strings.TrimSpace(d.ID)
	d.Adapter = strings.TrimSpace(d.Adapter)
	d.Entity = strings.TrimSpace(d.Entity)
	d.Site = strings.TrimSpace(d.Site)
	d.APIBase = strings.TrimSpace(d.APIBase)
	d.DetailURL = strings.TrimSpace(d.DetailURL)
	if d.Adapter == "" {
		d.Adapter = d.ID
	}
	if d.Status == "" {
		d.Status = StatusEnabled
	}
	if d.Name == "" {
		d.Name = d.ID
	}
	for i := range d.Units {
		d.Units[i].UnitID = strings.TrimSpace(d.Units[i].UnitID)
	}
}

// Enabled 判断数据源是否启用。
func (d *Definition) Enabled() bool { return d.Status != StatusDisabled }

// Validate 校验必填字段。
func (d *Definition) Validate() error {
	if d.ID == "" {
		return fmt.Errorf("数据源 id 不能为空")
	}
	if d.Adapter == "" {
		return fmt.Errorf("数据源 %s 缺少 adapter 类型", d.ID)
	}
	if d.APIBase == "" {
		return fmt.Errorf("数据源 %s 缺少 api_base", d.ID)
	}
	if d.Status != "" && d.Status != StatusEnabled && d.Status != StatusDisabled {
		return fmt.Errorf("数据源 %s 的 status 非法: %s", d.ID, d.Status)
	}
	return nil
}
