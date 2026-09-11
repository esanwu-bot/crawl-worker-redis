// Package shikues 是 shikues（电子元器件站点）数据源适配器。
//
// 协议：JSON API
//
//	GET {api_base}/productType?type=<typeId>               -> {data:[{id,cn_name,en_name,...}]}
//	GET {api_base}/productList?product_type_id=&page=&limit= -> {data:[{a,id,b..t,pdf,...}], last_page}
package shikues

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

// Adapter shikues 适配器。
type Adapter struct {
	client  *httpx.Client
	apiBase string
	source  string
	entity  string
	typeID  int
}

// New 构造适配器。
func New(client *httpx.Client, apiBase, sourceName, entity string, typeID int) *Adapter {
	if entity == "" {
		entity = "model"
	}
	return &Adapter{client: client, apiBase: apiBase, source: sourceName, entity: entity, typeID: typeID}
}

// Source 数据源标识。
func (a *Adapter) Source() string { return a.source }

// Discover 发现采集单元：按产品大类拉取系列目录。
func (a *Adapter) Discover(ctx context.Context, sink source.UnitSink) ([]task.Unit, error) {
	url := a.apiBase + "/productType"
	resp, err := a.client.GetJSON(ctx, url, map[string]string{
		"type": strconv.Itoa(a.typeID),
		"a":    "",
	})
	if err != nil {
		return nil, fmt.Errorf("拉取产品大类失败: %w", err)
	}
	items := coerce.ToSlice(resp["data"])
	units := make([]task.Unit, 0, len(items))
	for _, it := range items {
		m := coerce.ToMap(it)
		if m == nil {
			continue
		}
		id := coerce.ToInt(m["id"])
		if id <= 0 {
			continue
		}
		cn := coerce.Trim(m["cn_name"])
		en := coerce.Trim(m["en_name"])
		name := cn
		if name == "" {
			name = en
		}
		if sink != nil {
			_ = sink.UpsertSourceType(ctx, a.source, id, cn, en)
		}
		units = append(units, task.Unit{
			Entity:   a.entity,
			UnitID:   strconv.Itoa(id),
			UnitName: name,
		})
	}
	return units, nil
}

// ExecuteList 执行一次列表页采集并归一化为 Canonical Record。
func (a *Adapter) ExecuteList(ctx context.Context, t *task.Payload) (*source.ListResult, error) {
	limit := 15
	if t.Params != nil {
		if v, ok := t.Params["limit"]; ok {
			limit = coerce.ToInt(v)
		}
	}
	url := a.apiBase + "/productList"
	resp, err := a.client.GetJSON(ctx, url, map[string]string{
		"product_type_id": t.Cursor.UnitID,
		"page":            strconv.Itoa(t.Cursor.Page),
		"limit":           strconv.Itoa(limit),
		"a":               "",
	})
	if err != nil {
		return nil, err
	}
	items := coerce.ToSlice(resp["data"])
	lastPage := coerce.ToInt(resp["last_page"])
	unitName := t.Cursor.UnitName

	rows := toModelRows(items, atoi(t.Cursor.UnitID), unitName, url)
	out := make([]record.Canonical, 0, len(rows))
	for _, r := range rows {
		externalID := r.Model
		if r.RemoteID > 0 {
			externalID = strconv.Itoa(r.RemoteID)
		}
		out = append(out, record.Canonical{
			JobID:      t.JobID,
			Source:     a.source,
			Entity:     a.entity,
			UnitID:     t.Cursor.UnitID,
			UnitName:   unitName,
			ExternalID: externalID,
			Title:      r.Model,
			URL:        r.SourceURL,
			Page:       t.Cursor.Page,
			Payload: map[string]any{
				"model":     r.Model,
				"type_id":   r.TypeID,
				"type_name": r.TypeName,
				"package":   r.Package,
				"pdf":       r.PDF,
				"specs":     r.Specs,
			},
			Raw: r.Raw,
		})
	}

	ended := lastPage > 0 && t.Cursor.Page >= lastPage
	if lastPage == 0 && len(items) == 0 {
		ended = true
	}
	return &source.ListResult{
		Page:     t.Cursor.Page,
		LastPage: lastPage,
		Ended:    ended,
		Records:  out,
	}, nil
}

// HealthCheck 探测数据源可用性。
func (a *Adapter) HealthCheck(ctx context.Context) error {
	_, err := a.client.GetJSON(ctx, a.apiBase+"/productType", map[string]string{
		"type": strconv.Itoa(a.typeID), "a": "",
	})
	return err
}

func atoi(s string) int {
	n, _ := strconv.Atoi(s)
	return n
}
