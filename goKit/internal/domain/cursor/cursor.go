// Package cursor 把 Redis 中的采集游标 Hash 正式建模为“采集状态机”。
package cursor

import "strconv"

// Status 采集单元进度状态。
type Status string

const (
	// StatusPending 已播种，尚未处理。
	StatusPending Status = "pending"
	// StatusActive 正在翻页采集。
	StatusActive Status = "active"
	// StatusDone 已完成（达到末页或页数上限）。
	StatusDone Status = "done"
	// StatusDead 重试超限转入死信。
	StatusDead Status = "dead"
	// StatusCancelled 所属作业被取消。
	StatusCancelled Status = "cancelled"
)

// Cursor 是一个 (source, entity, unit) 的分页采集状态。
type Cursor struct {
	Source     string
	Entity     string
	UnitID     string
	UnitName   string
	Status     Status
	DonePages  int
	TotalPages int
	Rows       int
	Attempts   int
	UpdatedAt  string
}

// New 构造一个初始游标。
func New(source, entity, unitID, unitName string) *Cursor {
	return &Cursor{
		Source:   source,
		Entity:   entity,
		UnitID:   unitID,
		UnitName: unitName,
		Status:   StatusPending,
	}
}

// Meta 返回写入游标 Hash 的元数据字段。
func (c *Cursor) Meta() map[string]any {
	return map[string]any{
		"source":    c.Source,
		"entity":    c.Entity,
		"unit_id":   c.UnitID,
		"unit_name": c.UnitName,
	}
}

// FromHash 把 Redis HGETALL 结果解析为 Cursor。
func FromHash(h map[string]string) *Cursor {
	c := &Cursor{Status: Status(h["status"])}
	c.Source = h["source"]
	c.Entity = h["entity"]
	c.UnitID = h["unit_id"]
	c.UnitName = h["unit_name"]
	c.DonePages = atoi(h["done_pages"])
	c.TotalPages = atoi(h["total_pages"])
	c.Rows = atoi(h["rows"])
	c.Attempts = atoi(h["attempts"])
	c.UpdatedAt = h["updated_at"]
	return c
}

func atoi(s string) int {
	n, _ := strconv.Atoi(s)
	return n
}
