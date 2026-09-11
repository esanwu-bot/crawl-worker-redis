package mysqlstore

import "context"

// UpsertSourceType 幂等 upsert 采集单元目录（source_types）。
func (s *Store) UpsertSourceType(ctx context.Context, source string, typeID int, cnName, enName string) error {
	_, err := s.db.ExecContext(ctx,
		`INSERT INTO source_types (source, type_id, cn_name, en_name, updated_at)
		 VALUES (?, ?, ?, ?, ?)
		 ON DUPLICATE KEY UPDATE
		   cn_name = VALUES(cn_name),
		   en_name = VALUES(en_name),
		   updated_at = VALUES(updated_at)`,
		source, typeID, cnName, enName, nowStr())
	return err
}

// CountSourceTypes 统计采集单元目录数量。
func (s *Store) CountSourceTypes(ctx context.Context) (int, error) {
	var n int
	err := s.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM source_types`).Scan(&n)
	return n, err
}

// SourceTypeRow 是目录表的一行。
type SourceTypeRow struct {
	Source string `json:"source"`
	TypeID int    `json:"type_id"`
	CnName string `json:"cn_name"`
	EnName string `json:"en_name"`
}

// ListSourceTypes 列出来源下（或全部）的采集单元。
func (s *Store) ListSourceTypes(ctx context.Context, source string) ([]SourceTypeRow, error) {
	q := `SELECT source, type_id, cn_name, en_name FROM source_types`
	args := []any{}
	if source != "" {
		q += ` WHERE source = ?`
		args = append(args, source)
	}
	q += ` ORDER BY source, type_id`
	rows, err := s.db.QueryContext(ctx, q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []SourceTypeRow
	for rows.Next() {
		var r SourceTypeRow
		if err := rows.Scan(&r.Source, &r.TypeID, &r.CnName, &r.EnName); err != nil {
			return nil, err
		}
		out = append(out, r)
	}
	return out, rows.Err()
}
