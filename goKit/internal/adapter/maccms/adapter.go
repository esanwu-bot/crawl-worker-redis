// Package maccms 是 MacCMS V10 采集接口数据源适配器。
//
// 协议：MacCMS 标准 JSON 接口（ac=list / ac=detail）
//
//	GET {api_base}?ac=list&t={typeId}&pg={page}&h={hours}
//	GET {api_base}?ac=detail&ids={id}
package maccms

import (
	"context"
	"fmt"
	"strconv"

	"crawlkit/internal/adapter/coerce"
	"crawlkit/internal/domain/record"
	"crawlkit/internal/domain/source"
	"crawlkit/internal/domain/task"
	"crawlkit/internal/infrastructure/httpx"
)

// UnitDef 是配置文件中静态声明的采集单元（必填 unit_id）。
type UnitDef struct {
	UnitID   string
	UnitName string
	Params   map[string]any
}

// Config 构造 MacCMS 适配器的参数。
type Config struct {
	Source    string
	Site      string
	APIBase   string
	Entity    string
	PageSize  int
	DetailURL string
	Units     []UnitDef
}

// Adapter MacCMS 适配器。
type Adapter struct {
	client *httpx.Client
	cfg    Config
}

// New 构造适配器。
func New(client *httpx.Client, cfg Config) *Adapter {
	if cfg.Entity == "" {
		cfg.Entity = "vod"
	}
	if cfg.PageSize <= 0 {
		cfg.PageSize = 20
	}
	return &Adapter{client: client, cfg: cfg}
}

// Source 数据源标识。
func (a *Adapter) Source() string { return a.cfg.Source }

// Discover 返回配置声明的采集单元（MacCMS 的分类需人工/Agent 指定）。
func (a *Adapter) Discover(ctx context.Context, sink source.UnitSink) ([]task.Unit, error) {
	units := make([]task.Unit, 0, len(a.cfg.Units))
	for _, u := range a.cfg.Units {
		if u.UnitID == "" {
			continue
		}
		unit := task.Unit{
			Entity:   a.cfg.Entity,
			UnitID:   u.UnitID,
			UnitName: u.UnitName,
			Params:   u.Params,
		}
		units = append(units, unit)
		if sink != nil {
			_ = sink.UpsertSourceType(ctx, a.cfg.Source, atoi(u.UnitID), u.UnitName, "")
		}
	}
	return units, nil
}

// ExecuteList 采集某分类的一页。
func (a *Adapter) ExecuteList(ctx context.Context, t *task.Payload) (*source.ListResult, error) {
	page := t.Cursor.Page
	resp, err := a.client.GetJSON(ctx, a.cfg.APIBase, map[string]string{
		"ac": "list",
		"t":  t.Cursor.UnitID,
		"pg": strconv.Itoa(page),
	})
	if err != nil {
		return nil, fmt.Errorf("MacCMS 列表请求失败: %w", err)
	}
	items := coerce.ToSlice(resp["list"])
	limit := resp["limit"]
	total := resp["total"]
	pageCount := resp["pagecount"]
	if pageCount == nil {
		pageCount = resp["page_count"]
	}

	lastPage := coerce.ToInt(pageCount)
	if lastPage == 0 {
		lastPage = calcPageCount(coerce.ToInt(total), coerce.ToInt(limit), a.cfg.PageSize)
	}

	unitName := t.Cursor.UnitName
	out := make([]record.Canonical, 0, len(items))
	for _, it := range items {
		m := coerce.ToMap(it)
		if m == nil {
			continue
		}
		vodID := coerce.ToInt(m["vod_id"])
		if vodID <= 0 {
			continue
		}
		title := coerce.Trim(m["vod_name"])
		out = append(out, record.Canonical{
			JobID:      t.JobID,
			Source:     a.cfg.Source,
			Entity:     a.cfg.Entity,
			UnitID:     t.Cursor.UnitID,
			UnitName:   unitName,
			ExternalID: strconv.Itoa(vodID),
			Title:      title,
			URL:        coerce.Trim(m["vod_play_url"]),
			Image:      a.absURL(coerce.Trim(m["vod_pic"])),
			SourceURL:  a.detailURL(vodID),
			Page:       page,
			Payload: map[string]any{
				"vod_id":      vodID,
				"vod_name":    title,
				"type_id":     coerce.ToInt(m["type_id"]),
				"type_name":   coerce.Trim(m["type_name"]),
				"vod_time":    coerce.Trim(m["vod_time"]),
				"vod_remarks": coerce.Trim(m["vod_remarks"]),
				"vod_year":    coerce.Trim(m["vod_year"]),
				"vod_area":    coerce.Trim(m["vod_area"]),
				"vod_lang":    coerce.Trim(m["vod_lang"]),
			},
			Raw: m,
		})
	}

	ended := false
	if lastPage > 0 {
		ended = page >= lastPage
	}
	if len(items) == 0 {
		ended = true
	}
	return &source.ListResult{Page: page, LastPage: lastPage, Ended: ended, Records: out}, nil
}

// HealthCheck 探测数据源可用性。
func (a *Adapter) HealthCheck(ctx context.Context) error {
	_, err := a.client.GetJSON(ctx, a.cfg.APIBase, map[string]string{"ac": "list", "pg": "1"})
	return err
}

func (a *Adapter) absURL(u string) string {
	if u == "" || len(u) > 4 && u[:4] == "http" {
		return u
	}
	return a.cfg.Site + "/" + u
}

func (a *Adapter) detailURL(id int) string {
	if a.cfg.DetailURL != "" {
		return replaceID(a.cfg.DetailURL, id)
	}
	return a.cfg.APIBase + "?ac=detail&ids=" + strconv.Itoa(id)
}

func replaceID(tpl string, id int) string {
	out := make([]byte, 0, len(tpl)+8)
	for i := 0; i < len(tpl); i++ {
		if i+4 <= len(tpl) && tpl[i:i+4] == "{id}" {
			out = append(out, []byte(strconv.Itoa(id))...)
			i += 3
			continue
		}
		out = append(out, tpl[i])
	}
	return string(out)
}

func calcPageCount(total, limit, fallback int) int {
	if limit <= 0 {
		limit = fallback
	}
	if limit <= 0 || total <= 0 {
		return 0
	}
	return (total + limit - 1) / limit
}

func atoi(s string) int {
	n, _ := strconv.Atoi(s)
	return n
}
