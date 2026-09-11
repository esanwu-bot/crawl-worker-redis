// Package task 定义通用任务载荷契约（Redis Stream 消息 p 字段的内容）。
//
// Runtime（Producer / Worker / RedisStore）只理解本结构；
// 任何数据源特定语义（协议、字段、分页参数）都封装在 Adapter 内。
//
// 载荷示例：
//
//	{
//	  "job_id":    "job-20260911...",
//	  "source":    "shikues",
//	  "entity":    "model",
//	  "operation": "list",
//	  "cursor":    {"unit_id": "3", "unit_name": "肖特基二极管", "page": 1},
//	  "params":    {"limit": 15},
//	  "target_pages": 3
//	}
package task

import (
	"encoding/json"
	"fmt"
)

// OpList 列表采集操作（V1 只做 list；detail/export 走 Operation 扩展）。
const OpList = "list"

// Cursor 是任务载荷内的分页游标（同时用于定位 Redis 游标 Hash）。
type Cursor struct {
	UnitID   string `json:"unit_id"`
	UnitName string `json:"unit_name"`
	Page     int    `json:"page"`
}

// Payload 是一个可独立消费的任务。
type Payload struct {
	JobID       string         `json:"job_id,omitempty"`
	Source      string         `json:"source"`
	Entity      string         `json:"entity"`
	Operation   string         `json:"operation"`
	Cursor      Cursor         `json:"cursor"`
	Params      map[string]any `json:"params,omitempty"`
	TargetPages int            `json:"target_pages,omitempty"`
}

// Unit 描述 Adapter 发现出的一个采集单元（用于生成首页任务）。
type Unit struct {
	Entity   string         `json:"entity"`
	UnitID   string         `json:"unit_id"`
	UnitName string         `json:"unit_name"`
	Params   map[string]any `json:"params,omitempty"`
}

// Home 由采集单元生成首页任务。
func Home(u Unit, source string, page int, targetPages int, jobID string) *Payload {
	if page < 1 {
		page = 1
	}
	p := &Payload{
		JobID:     jobID,
		Source:    source,
		Entity:    u.Entity,
		Operation: OpList,
		Cursor: Cursor{
			UnitID:   u.UnitID,
			UnitName: u.UnitName,
			Page:     page,
		},
	}
	if len(u.Params) > 0 {
		p.Params = u.Params
	}
	if targetPages > 0 {
		p.TargetPages = targetPages
	}
	return p
}

// Next 生成下一页任务（复用同 unit 的源/实体/参数，仅推进 page）。
func (p *Payload) Next() *Payload {
	next := *p
	next.Cursor.Page = p.Cursor.Page + 1
	if p.Params != nil {
		cp := make(map[string]any, len(p.Params))
		for k, v := range p.Params {
			cp[k] = v
		}
		next.Params = cp
	}
	return &next
}

// Valid 校验是否为一个合法的 list 任务。
func (p *Payload) Valid() bool {
	return p != nil &&
		p.Operation == OpList &&
		p.Source != "" &&
		p.Entity != "" &&
		p.Cursor.UnitID != "" &&
		p.Cursor.Page > 0
}

// CursorKey 游标 Hash 键：cur:{source}:{entity}:{unit_id}。
func (p *Payload) CursorKey() string {
	return fmt.Sprintf("cur:%s:%s:%s", p.Source, p.Entity, p.Cursor.UnitID)
}

// NextPageLockKey 幂等“追加下一页”闸门键：addlock:{source}:{entity}:{unit_id}:{page}。
func (p *Payload) NextPageLockKey() string {
	return fmt.Sprintf("addlock:%s:%s:%s:%d", p.Source, p.Entity, p.Cursor.UnitID, p.Cursor.Page)
}

// Describe 可读日志描述：source[unit_name] p{page}。
func (p *Payload) Describe() string {
	unit := p.Cursor.UnitName
	if unit == "" {
		unit = p.Cursor.UnitID
	}
	return fmt.Sprintf("[%s|%s|p%d]", p.Source, unit, p.Cursor.Page)
}

// TargetPages 目标页上限：显式 TargetPages 优先，否则用 fallback。
func (p *Payload) TargetPagesOr(fallback int) int {
	if p.TargetPages > 0 {
		return p.TargetPages
	}
	return fallback
}

// Encode 序列化为 Redis 消息体。
func (p *Payload) Encode() ([]byte, error) {
	return json.Marshal(p)
}

// Decode 从 Redis 消息体解析任务；兼容 JSON 字符串二次包装的情况。
func Decode(raw []byte) (*Payload, error) {
	var p Payload
	if err := json.Unmarshal(raw, &p); err != nil {
		// 站点/中间件偶发把 JSON 再包一层字符串
		var s string
		if err2 := json.Unmarshal(raw, &s); err2 == nil {
			if err3 := json.Unmarshal([]byte(s), &p); err3 == nil {
				return &p, nil
			}
		}
		return nil, err
	}
	return &p, nil
}
