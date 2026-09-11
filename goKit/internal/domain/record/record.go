// Package record 定义跨数据源统一的 Canonical Record。
//
// 这是 Runtime 与 Adapter 之间唯一的“结果契约”：
// 任何数据源抓到的行都必须清洗成本结构后交给 Sink 落库，
// Runtime 因此对领域细节零感知。
package record

// Canonical 是一条标准化采集结果（对应表 crawl_records 的一行）。
type Canonical struct {
	JobID      string `json:"job_id,omitempty"`
	Source     string `json:"source"`
	Entity     string `json:"entity"`
	UnitID     string `json:"unit_id"`
	UnitName   string `json:"unit_name"`
	ExternalID string `json:"external_id"`
	Title      string `json:"title"`
	URL        string `json:"url"`
	Image      string `json:"image"`
	SourceURL  string `json:"source_url"`
	Page       int    `json:"page"`
	// Payload 为领域可检索字段（按 schema 检索用），Raw 为原始行全量备份。
	Payload map[string]any `json:"payload,omitempty"`
	Raw     map[string]any `json:"raw,omitempty"`
}

// Valid 判断是否具备落库的最小必要信息。
func (r Canonical) Valid() bool {
	return r.Source != "" && r.ExternalID != ""
}
