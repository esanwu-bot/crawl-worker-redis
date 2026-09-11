package mysqlstore

import (
	"context"
	"encoding/json"
	"strings"

	"crawlkit/internal/domain/record"
)

// UpsertRecords 批量幂等 upsert Canonical Record。
// 唯一键 (source, entity, unit_id, external_id)，重复采集不产生新行。返回写入行数。
func (s *Store) UpsertRecords(ctx context.Context, records []record.Canonical) (int, error) {
	valid := make([]record.Canonical, 0, len(records))
	for _, r := range records {
		if r.Valid() {
			valid = append(valid, r)
		}
	}
	if len(valid) == 0 {
		return 0, nil
	}

	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return 0, err
	}
	stmt, err := tx.PrepareContext(ctx,
		`INSERT INTO crawl_records
		   (job_id, source, entity, unit_id, unit_name, external_id, title, url, image,
		    source_url, page, payload_json, raw_json, crawled_at, updated_at)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
		 ON DUPLICATE KEY UPDATE
		   job_id       = VALUES(job_id),
		   unit_name    = VALUES(unit_name),
		   title        = VALUES(title),
		   url          = VALUES(url),
		   image        = VALUES(image),
		   source_url   = VALUES(source_url),
		   page         = VALUES(page),
		   payload_json = VALUES(payload_json),
		   raw_json     = VALUES(raw_json),
		   updated_at   = VALUES(updated_at)`)
	if err != nil {
		_ = tx.Rollback()
		return 0, err
	}
	defer stmt.Close()

	now := nowStr()
	n := 0
	for _, r := range valid {
		payload, _ := json.Marshal(orEmptyMap(r.Payload))
		raw, _ := json.Marshal(orEmptyMap(r.Raw))
		if _, err := stmt.ExecContext(ctx,
			r.JobID, r.Source, r.Entity, r.UnitID, r.UnitName, r.ExternalID,
			r.Title, r.URL, r.Image, r.SourceURL, r.Page, string(payload), string(raw), now, now,
		); err != nil {
			_ = tx.Rollback()
			return n, err
		}
		n++
	}
	if err := tx.Commit(); err != nil {
		return 0, err
	}
	return n, nil
}

// CountRecords 统计记录数（可按 source 过滤）。
func (s *Store) CountRecords(ctx context.Context, source string) (int, error) {
	q := `SELECT COUNT(*) FROM crawl_records`
	args := []any{}
	if source != "" {
		q += ` WHERE source = ?`
		args = append(args, source)
	}
	var n int
	err := s.db.QueryRowContext(ctx, q, args...).Scan(&n)
	return n, err
}

// EntityStat 按 source+entity 聚合的记录数。
type EntityStat struct {
	Source string `json:"source"`
	Entity string `json:"entity"`
	Count  int    `json:"count"`
}

// RecordsByEntity 跨数据源记录一览。
func (s *Store) RecordsByEntity(ctx context.Context) ([]EntityStat, error) {
	rows, err := s.db.QueryContext(ctx,
		`SELECT source, entity, COUNT(*) AS cnt FROM crawl_records GROUP BY source, entity ORDER BY cnt DESC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []EntityStat
	for rows.Next() {
		var e EntityStat
		if err := rows.Scan(&e.Source, &e.Entity, &e.Count); err != nil {
			return nil, err
		}
		out = append(out, e)
	}
	return out, rows.Err()
}

// RecordView 是记录查询的返回结构。
type RecordView struct {
	JobID      string `json:"job_id"`
	Source     string `json:"source"`
	Entity     string `json:"entity"`
	UnitID     string `json:"unit_id"`
	UnitName   string `json:"unit_name"`
	ExternalID string `json:"external_id"`
	Title      string `json:"title"`
	URL        string `json:"url"`
	Image      string `json:"image"`
	Payload    string `json:"payload_json"`
	Raw        string `json:"raw_json"`
}

// SearchRecords 按 source/entity/unit/keyword 过滤查询记录。
func (s *Store) SearchRecords(ctx context.Context, source, entity, unitID, keyword string, limit int) ([]RecordView, error) {
	if limit <= 0 {
		limit = 20
	}
	q := `SELECT job_id, source, entity, unit_id, unit_name, external_id, title, url, image, payload_json, raw_json
	      FROM crawl_records WHERE 1=1`
	args := []any{}
	if source != "" {
		q += ` AND source = ?`
		args = append(args, source)
	}
	if entity != "" {
		q += ` AND entity = ?`
		args = append(args, entity)
	}
	if unitID != "" {
		q += ` AND unit_id = ?`
		args = append(args, unitID)
	}
	if keyword != "" {
		q += ` AND title LIKE ?`
		args = append(args, "%"+keyword+"%")
	}
	q += ` ORDER BY id DESC LIMIT ?`
	args = append(args, limit)

	rows, err := s.db.QueryContext(ctx, q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []RecordView
	for rows.Next() {
		var v RecordView
		var payload, raw, url, image *string
		if err := rows.Scan(&v.JobID, &v.Source, &v.Entity, &v.UnitID, &v.UnitName,
			&v.ExternalID, &v.Title, &url, &image, &payload, &raw); err != nil {
			return nil, err
		}
		if url != nil {
			v.URL = *url
		}
		if image != nil {
			v.Image = *image
		}
		if payload != nil {
			v.Payload = *payload
		}
		if raw != nil {
			v.Raw = *raw
		}
		out = append(out, v)
	}
	return out, rows.Err()
}

// RecentRecords 最近落库记录。
func (s *Store) RecentRecords(ctx context.Context, limit int, source string) ([]RecordView, error) {
	return s.SearchRecords(ctx, source, "", "", "", limit)
}

func orEmptyMap(m map[string]any) map[string]any {
	if m == nil {
		return map[string]any{}
	}
	return m
}

// NormalizeKeyword 去掉首尾空白，便于查询参数清理。
func NormalizeKeyword(s string) string { return strings.TrimSpace(s) }
