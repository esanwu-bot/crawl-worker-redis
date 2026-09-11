// Package job 定义采集作业（crawl_jobs）的领域模型。
package job

import "time"

// Status 作业生命周期状态。
type Status string

const (
	StatusSeeding   Status = "seeding"
	StatusActive    Status = "active"
	StatusPaused    Status = "paused"
	StatusDone      Status = "done"
	StatusDead      Status = "dead"
	StatusCancelled Status = "cancelled"
)

// ScopeItem 是本轮播种的采集单元快照。
type ScopeItem struct {
	Entity   string `json:"entity"`
	UnitID   string `json:"unit_id"`
	UnitName string `json:"unit_name"`
}

// Job 对应 crawl_jobs 一行。
type Job struct {
	ID         string      `json:"id"`
	Source     string      `json:"source"`
	Status     Status      `json:"status"`
	UnitsTotal int         `json:"units_total"`
	UnitsDone  int         `json:"units_done"`
	UnitsDead  int         `json:"units_dead"`
	Records    int         `json:"records"`
	Scope      []ScopeItem `json:"scope"`
	CreatedAt  time.Time   `json:"created_at"`
	UpdatedAt  time.Time   `json:"updated_at"`
}

// Finished 判断作业是否所有单元都已收尾。
func (j *Job) Finished() bool {
	return j.UnitsDone+j.UnitsDead >= j.UnitsTotal
}
