package mysqlstore

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"

	"crawlkit/internal/domain/source"
)

// ---------- Source Definition ----------

// ListSources 列出全部配置式数据源。
func (s *Store) ListSources(ctx context.Context) ([]source.Definition, error) {
	rows, err := s.db.QueryContext(ctx,
		`SELECT id, name, adapter, entity, site, api_base, page_size, detail_url, status, config_json, created_at, updated_at
		   FROM sources ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := make([]source.Definition, 0)
	for rows.Next() {
		d, err := scanSource(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, *d)
	}
	return out, rows.Err()
}

// GetSource 读取单个数据源，不存在返回 nil。
func (s *Store) GetSource(ctx context.Context, id string) (*source.Definition, error) {
	row := s.db.QueryRowContext(ctx,
		`SELECT id, name, adapter, entity, site, api_base, page_size, detail_url, status, config_json, created_at, updated_at
		   FROM sources WHERE id = ?`, id)
	return scanSource(row)
}

// UpsertSource 全量写入数据源（创建或更新）。
func (s *Store) UpsertSource(ctx context.Context, d source.Definition) error {
	d.Normalize()
	cfg, _ := json.Marshal(d)
	_, err := s.db.ExecContext(ctx,
		`INSERT INTO sources (id, name, adapter, entity, site, api_base, page_size, detail_url, status, config_json, created_at, updated_at)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
		 ON DUPLICATE KEY UPDATE
		   name = VALUES(name), adapter = VALUES(adapter), entity = VALUES(entity),
		   site = VALUES(site), api_base = VALUES(api_base), page_size = VALUES(page_size),
		   detail_url = VALUES(detail_url), status = VALUES(status),
		   config_json = VALUES(config_json), updated_at = VALUES(updated_at)`,
		d.ID, d.Name, d.Adapter, d.Entity, d.Site, d.APIBase, d.PageSize, d.DetailURL, d.Status, string(cfg), nowStr(), nowStr())
	return err
}

// SeedSource 仅当数据源不存在时写入（用于启动时从 config 播种）。
func (s *Store) SeedSource(ctx context.Context, d source.Definition) error {
	d.Normalize()
	cfg, _ := json.Marshal(d)
	_, err := s.db.ExecContext(ctx,
		`INSERT IGNORE INTO sources (id, name, adapter, entity, site, api_base, page_size, detail_url, status, config_json, created_at, updated_at)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		d.ID, d.Name, d.Adapter, d.Entity, d.Site, d.APIBase, d.PageSize, d.DetailURL, d.Status, string(cfg), nowStr(), nowStr())
	return err
}

// SetSourceStatus 启用/停用数据源。
func (s *Store) SetSourceStatus(ctx context.Context, id, status string) error {
	_, err := s.db.ExecContext(ctx, `UPDATE sources SET status = ?, updated_at = ? WHERE id = ?`, status, nowStr(), id)
	return err
}

// DeleteSource 删除数据源及其 entity/schema/fields。
func (s *Store) DeleteSource(ctx context.Context, id string) error {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }()
	if _, err := tx.ExecContext(ctx,
		`DELETE f FROM source_fields f JOIN source_schemas sc ON f.schema_id = sc.id WHERE sc.source = ?`, id); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM source_schemas WHERE source = ?`, id); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM source_entities WHERE source = ?`, id); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM sources WHERE id = ?`, id); err != nil {
		return err
	}
	return tx.Commit()
}

// ---------- Entities ----------

// UpsertEntity 幂等写入数据源的采集对象类型。
func (s *Store) UpsertEntity(ctx context.Context, src, entity, name string) error {
	_, err := s.db.ExecContext(ctx,
		`INSERT INTO source_entities (source, entity, name, status, created_at, updated_at)
		 VALUES (?, ?, ?, 'enabled', ?, ?)
		 ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = VALUES(updated_at)`,
		src, entity, name, nowStr(), nowStr())
	return err
}

// ListEntities 列出数据源（或全部）的采集对象类型。
func (s *Store) ListEntities(ctx context.Context, src string) ([]map[string]any, error) {
	q := `SELECT source, entity, name, status, updated_at FROM source_entities`
	args := []any{}
	if src != "" {
		q += ` WHERE source = ?`
		args = append(args, src)
	}
	q += ` ORDER BY source, entity`
	rows, err := s.db.QueryContext(ctx, q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := make([]map[string]any, 0)
	for rows.Next() {
		var source, entity, name, status, updated string
		if err := rows.Scan(&source, &entity, &name, &status, &updated); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"source": source, "entity": entity, "name": name, "status": status, "updated_at": updated,
		})
	}
	return out, rows.Err()
}

// ---------- Schema ----------

// GetSchema 返回实体当前生效的 Schema：优先 published，其次最高版本。
func (s *Store) GetSchema(ctx context.Context, src, entity string) (*source.Schema, error) {
	row := s.db.QueryRowContext(ctx,
		`SELECT id, source, entity, version, status, mapping_json, pagination_json, created_at, updated_at
		   FROM source_schemas WHERE source = ? AND entity = ?
		  ORDER BY (status = 'published') DESC, version DESC LIMIT 1`, src, entity)
	return s.scanSchema(ctx, row)
}

// GetSchemaByID 按主键读取 Schema（含字段）。
func (s *Store) GetSchemaByID(ctx context.Context, id int64) (*source.Schema, error) {
	row := s.db.QueryRowContext(ctx,
		`SELECT id, source, entity, version, status, mapping_json, pagination_json, created_at, updated_at
		   FROM source_schemas WHERE id = ?`, id)
	return s.scanSchema(ctx, row)
}

// ListSchemas 列出一个数据源下所有版本的 Schema。
func (s *Store) ListSchemas(ctx context.Context, src, entity string) ([]source.Schema, error) {
	q := `SELECT id, source, entity, version, status, mapping_json, pagination_json, created_at, updated_at FROM source_schemas WHERE 1=1`
	args := []any{}
	if src != "" {
		q += ` AND source = ?`
		args = append(args, src)
	}
	if entity != "" {
		q += ` AND entity = ?`
		args = append(args, entity)
	}
	q += ` ORDER BY source, entity, version DESC`
	rows, err := s.db.QueryContext(ctx, q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := make([]source.Schema, 0)
	for rows.Next() {
		sch, err := s.scanSchema(ctx, rows)
		if err != nil {
			return nil, err
		}
		if sch != nil {
			out = append(out, *sch)
		}
	}
	return out, rows.Err()
}

// SaveSchema 创建/更新一个 Schema 版本（含字段，事务写入）。
//
// ID == 0 时新建下一个版本；否则更新该版本。
func (s *Store) SaveSchema(ctx context.Context, sch source.Schema) (*source.Schema, error) {
	sch.Normalize()
	if err := sch.Validate(); err != nil {
		return nil, err
	}
	mapping, _ := json.Marshal(sch.Fields)
	pg, _ := json.Marshal(sch.Pagination)

	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	if sch.ID == 0 {
		var maxVer sql.NullInt64
		if err := tx.QueryRowContext(ctx,
			`SELECT MAX(version) FROM source_schemas WHERE source = ? AND entity = ?`,
			sch.Source, sch.Entity).Scan(&maxVer); err != nil {
			return nil, err
		}
		sch.Version = int(maxVer.Int64) + 1
		res, err := tx.ExecContext(ctx,
			`INSERT INTO source_schemas (source, entity, version, status, mapping_json, pagination_json, created_at, updated_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
			sch.Source, sch.Entity, sch.Version, sch.Status, string(mapping), string(pg), nowStr(), nowStr())
		if err != nil {
			return nil, err
		}
		sch.ID, _ = res.LastInsertId()
	} else {
		if _, err := tx.ExecContext(ctx,
			`UPDATE source_schemas SET status = ?, mapping_json = ?, pagination_json = ?, updated_at = ? WHERE id = ?`,
			sch.Status, string(mapping), string(pg), nowStr(), sch.ID); err != nil {
			return nil, err
		}
		if _, err := tx.ExecContext(ctx, `DELETE FROM source_fields WHERE schema_id = ?`, sch.ID); err != nil {
			return nil, err
		}
	}
	if err := insertFields(ctx, tx, sch.ID, sch.Fields); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return s.GetSchemaByID(ctx, sch.ID)
}

// PublishSchema 发布指定版本，并把同实体其他版本置回 draft。
func (s *Store) PublishSchema(ctx context.Context, id int64) error {
	sch, err := s.GetSchemaByID(ctx, id)
	if err != nil {
		return err
	}
	if sch == nil {
		return fmt.Errorf("schema 不存在: %d", id)
	}
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }()
	if _, err := tx.ExecContext(ctx,
		`UPDATE source_schemas SET status = 'draft', updated_at = ? WHERE source = ? AND entity = ? AND id <> ?`,
		nowStr(), sch.Source, sch.Entity, id); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx,
		`UPDATE source_schemas SET status = 'published', updated_at = ? WHERE id = ?`, nowStr(), id); err != nil {
		return err
	}
	return tx.Commit()
}

// DeleteSchema 删除一个 Schema 版本及其字段。
func (s *Store) DeleteSchema(ctx context.Context, id int64) error {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }()
	if _, err := tx.ExecContext(ctx, `DELETE FROM source_fields WHERE schema_id = ?`, id); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM source_schemas WHERE id = ?`, id); err != nil {
		return err
	}
	return tx.Commit()
}

// ---------- 内部 ----------

func insertFields(ctx context.Context, tx *sql.Tx, schemaID int64, fields []source.SchemaField) error {
	stmt, err := tx.PrepareContext(ctx,
		`INSERT INTO source_fields (schema_id, field, source_path, type, required, remark, sort)
		 VALUES (?, ?, ?, ?, ?, ?, ?)`)
	if err != nil {
		return err
	}
	defer stmt.Close()
	for i, f := range fields {
		if _, err := stmt.ExecContext(ctx, schemaID, f.Field, f.SourcePath, f.Type, boolToInt(f.Required), f.Remark, i); err != nil {
			return err
		}
	}
	return nil
}

func (s *Store) scanSchema(ctx context.Context, row rowScanner) (*source.Schema, error) {
	sch := &source.Schema{}
	var status string
	var mapping, pagination sql.NullString
	var created, updated sql.NullString
	err := row.Scan(&sch.ID, &sch.Source, &sch.Entity, &sch.Version, &status, &mapping, &pagination, &created, &updated)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, nil
		}
		return nil, err
	}
	sch.Status = status
	if mapping.Valid && mapping.String != "" {
		_ = json.Unmarshal([]byte(mapping.String), &sch.Fields)
	}
	if pagination.Valid && pagination.String != "" {
		_ = json.Unmarshal([]byte(pagination.String), &sch.Pagination)
	}
	if created.Valid {
		sch.CreatedAt, _ = parseTime(created.String)
	}
	if updated.Valid {
		sch.UpdatedAt, _ = parseTime(updated.String)
	}
	if fields, err := s.listFields(ctx, sch.ID); err == nil {
		sch.Fields = fields
	}
	return sch, nil
}

func (s *Store) listFields(ctx context.Context, schemaID int64) ([]source.SchemaField, error) {
	rows, err := s.db.QueryContext(ctx,
		`SELECT field, source_path, type, required, remark, sort FROM source_fields WHERE schema_id = ? ORDER BY sort, id`, schemaID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := make([]source.SchemaField, 0)
	for rows.Next() {
		var f source.SchemaField
		var req int
		if err := rows.Scan(&f.Field, &f.SourcePath, &f.Type, &req, &f.Remark, &f.Sort); err != nil {
			return nil, err
		}
		f.Required = req != 0
		out = append(out, f)
	}
	return out, rows.Err()
}

func scanSource(row rowScanner) (*source.Definition, error) {
	d := &source.Definition{}
	var configJSON sql.NullString
	var created, updated sql.NullString
	err := row.Scan(&d.ID, &d.Name, &d.Adapter, &d.Entity, &d.Site, &d.APIBase,
		&d.PageSize, &d.DetailURL, &d.Status, &configJSON, &created, &updated)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, nil
		}
		return nil, err
	}
	if configJSON.Valid && configJSON.String != "" {
		var full source.Definition
		if json.Unmarshal([]byte(configJSON.String), &full) == nil {
			if full.Units != nil {
				d.Units = full.Units
			}
		}
	}
	if created.Valid {
		d.CreatedAt, _ = parseTime(created.String)
	}
	if updated.Valid {
		d.UpdatedAt, _ = parseTime(updated.String)
	}
	return d, nil
}

func boolToInt(b bool) int {
	if b {
		return 1
	}
	return 0
}
