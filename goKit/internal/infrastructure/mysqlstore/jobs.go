package mysqlstore

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"

	"crawlkit/internal/domain/job"
)

// CreateJob 创建采集作业，返回作业 id。
func (s *Store) CreateJob(ctx context.Context, source string, scope []job.ScopeItem) (string, error) {
	id := newJobID()
	scopeJSON, _ := json.Marshal(scope)
	status := job.StatusActive
	if len(scope) == 0 {
		status = job.StatusDone
	}
	_, err := s.db.ExecContext(ctx,
		`INSERT INTO crawl_jobs (id, source, status, units_total, units_done, units_dead, records, scope_json, created_at, updated_at)
		 VALUES (?, ?, ?, ?, 0, 0, 0, ?, ?, ?)`,
		id, source, string(status), len(scope), string(scopeJSON), nowStr(), nowStr())
	if err != nil {
		return "", err
	}
	return id, nil
}

// GetJob 读取作业。
func (s *Store) GetJob(ctx context.Context, id string) (*job.Job, error) {
	row := s.db.QueryRowContext(ctx,
		`SELECT id, source, status, units_total, units_done, units_dead, records, scope_json, created_at, updated_at
		 FROM crawl_jobs WHERE id = ?`, id)
	return scanJob(row)
}

// ListJobs 列出最近作业。
func (s *Store) ListJobs(ctx context.Context, source string, limit int) ([]*job.Job, error) {
	if limit <= 0 {
		limit = 20
	}
	q := `SELECT id, source, status, units_total, units_done, units_dead, records, scope_json, created_at, updated_at FROM crawl_jobs`
	args := []any{}
	if source != "" {
		q += ` WHERE source = ?`
		args = append(args, source)
	}
	q += ` ORDER BY created_at DESC LIMIT ?`
	args = append(args, limit)
	rows, err := s.db.QueryContext(ctx, q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []*job.Job
	for rows.Next() {
		j, err := scanJobRows(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, j)
	}
	return out, rows.Err()
}

// UnitFinished 在一个采集单元收尾时回写作业状态机。
func (s *Store) UnitFinished(ctx context.Context, jobID string, done bool, records int) error {
	if jobID == "" {
		return nil
	}
	col := "units_dead"
	if done {
		col = "units_done"
	}
	if _, err := s.db.ExecContext(ctx,
		`UPDATE crawl_jobs SET `+col+` = `+col+` + 1, records = records + ?, updated_at = ? WHERE id = ?`,
		records, nowStr(), jobID); err != nil {
		return err
	}
	// 全部单元收尾后收敛终态：有死信归 dead，否则 done。
	_, err := s.db.ExecContext(ctx,
		`UPDATE crawl_jobs
		   SET status = CASE WHEN units_dead > 0 THEN 'dead' ELSE 'done' END, updated_at = ?
		 WHERE id = ? AND units_done + units_dead >= units_total`, nowStr(), jobID)
	return err
}

// SetJobStatus 显式设置作业状态（pause/cancel 等）。
func (s *Store) SetJobStatus(ctx context.Context, jobID string, status job.Status) error {
	if jobID == "" {
		return nil
	}
	_, err := s.db.ExecContext(ctx, `UPDATE crawl_jobs SET status = ?, updated_at = ? WHERE id = ?`,
		string(status), nowStr(), jobID)
	return err
}

// CountJobs 统计作业数。
func (s *Store) CountJobs(ctx context.Context, source string) (int, error) {
	q := `SELECT COUNT(*) FROM crawl_jobs`
	args := []any{}
	if source != "" {
		q += ` WHERE source = ?`
		args = append(args, source)
	}
	var n int
	err := s.db.QueryRowContext(ctx, q, args...).Scan(&n)
	return n, err
}

type rowScanner interface {
	Scan(dest ...any) error
}

func scanJob(row rowScanner) (*job.Job, error) {
	j := &job.Job{}
	var status, scopeJSON string
	var created, updated sql.NullString
	err := row.Scan(&j.ID, &j.Source, &status, &j.UnitsTotal, &j.UnitsDone, &j.UnitsDead,
		&j.Records, &scopeJSON, &created, &updated)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, nil
		}
		return nil, err
	}
	j.Status = job.Status(status)
	if scopeJSON != "" {
		_ = json.Unmarshal([]byte(scopeJSON), &j.Scope)
	}
	if created.Valid {
		j.CreatedAt, _ = parseTime(created.String)
	}
	if updated.Valid {
		j.UpdatedAt, _ = parseTime(updated.String)
	}
	return j, nil
}

func scanJobRows(rows *sql.Rows) (*job.Job, error) {
	return scanJob(rows)
}
