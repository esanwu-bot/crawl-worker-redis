// Package mysqlstore 是结果层实现：
// 自动建库建表（幂等），并以 (source, entity, unit_id, external_id) 唯一键做 Canonical Record 幂等 upsert。
package mysqlstore

import (
	"context"
	"database/sql"
	"fmt"
	"sort"
	"strings"
	"time"

	_ "github.com/go-sql-driver/mysql" // MySQL 驱动

	"crawlkit/internal/config"
	"crawlkit/migrations"
)

// Store 结果层访问对象。
type Store struct {
	db *sql.DB
}

// Open 连接 MySQL（默认端口不可达时依次探测备选端口），建库并执行迁移。
func Open(ctx context.Context, cfg config.MySQLConfig) (*Store, error) {
	ports := probePorts(cfg)
	var lastErr error
	for _, port := range ports {
		dsn := fmt.Sprintf("%s:%s@tcp(%s:%d)/?charset=%s&parseTime=true&loc=Local&timeout=10s",
			cfg.User, cfg.Pass, cfg.Host, port, cfg.Charset)
		db, err := sql.Open("mysql", dsn)
		if err != nil {
			lastErr = err
			continue
		}
		db.SetMaxOpenConns(20)
		db.SetMaxIdleConns(5)
		db.SetConnMaxLifetime(time.Hour)

		pingCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
		err = db.PingContext(pingCtx)
		cancel()
		if err != nil {
			_ = db.Close()
			lastErr = err
			continue
		}
		if _, err := db.ExecContext(ctx, fmt.Sprintf(
			"CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", cfg.DB)); err != nil {
			_ = db.Close()
			lastErr = err
			continue
		}
		// 关键：database/sql 连接池中 `USE` 只影响单条连接，
		// 因此关闭初始连接后改用带库名的 DSN 重新打开。
		_ = db.Close()
		withDB := fmt.Sprintf("%s:%s@tcp(%s:%d)/%s?charset=%s&parseTime=true&loc=Local&timeout=10s",
			cfg.User, cfg.Pass, cfg.Host, port, cfg.DB, cfg.Charset)
		db2, err := sql.Open("mysql", withDB)
		if err != nil {
			lastErr = err
			continue
		}
		db2.SetMaxOpenConns(20)
		db2.SetMaxIdleConns(5)
		db2.SetConnMaxLifetime(time.Hour)
		pingCtx2, cancel2 := context.WithTimeout(ctx, 5*time.Second)
		err = db2.PingContext(pingCtx2)
		cancel2()
		if err != nil {
			_ = db2.Close()
			lastErr = err
			continue
		}
		s := &Store{db: db2}
		if err := s.migrate(ctx); err != nil {
			_ = db2.Close()
			return nil, err
		}
		return s, nil
	}
	return nil, fmt.Errorf("MySQL 连接失败 (尝试端口 %v): %w", ports, lastErr)
}

// DB 暴露底层 *sql.DB。
func (s *Store) DB() *sql.DB { return s.db }

// Close 关闭连接。
func (s *Store) Close() error { return s.db.Close() }

func probePorts(cfg config.MySQLConfig) []int {
	set := map[int]bool{cfg.Port: true}
	for _, p := range cfg.AltPorts {
		set[p] = true
	}
	for _, p := range []int{3306, 3307, 3308} {
		set[p] = true
	}
	out := make([]int, 0, len(set))
	for p := range set {
		if p > 0 {
			out = append(out, p)
		}
	}
	sort.Ints(out)
	// 配置端口优先
	ordered := []int{cfg.Port}
	for _, p := range out {
		if p != cfg.Port {
			ordered = append(ordered, p)
		}
	}
	return ordered
}

// migrate 执行内置 SQL 迁移（幂等）。
func (s *Store) migrate(ctx context.Context) error {
	entries, err := migrations.FS.ReadDir(".")
	if err != nil {
		return fmt.Errorf("读取迁移目录失败: %w", err)
	}
	names := make([]string, 0, len(entries))
	for _, e := range entries {
		if !e.IsDir() && strings.HasSuffix(e.Name(), ".sql") {
			names = append(names, e.Name())
		}
	}
	sort.Strings(names)
	for _, name := range names {
		raw, err := migrations.FS.ReadFile(name)
		if err != nil {
			return fmt.Errorf("读取迁移 %s 失败: %w", name, err)
		}
		for _, stmt := range splitSQL(string(raw)) {
			if _, err := s.db.ExecContext(ctx, stmt); err != nil {
				return fmt.Errorf("执行迁移 %s 失败: %w\nSQL: %s", name, err, stmt)
			}
		}
	}
	return nil
}

// splitSQL 去掉整行注释并按分号拆分语句。
func splitSQL(raw string) []string {
	var cleaned []string
	for _, line := range strings.Split(raw, "\n") {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "--") || trimmed == "" {
			continue
		}
		cleaned = append(cleaned, line)
	}
	parts := strings.Split(strings.Join(cleaned, "\n"), ";")
	out := make([]string, 0, len(parts))
	for _, p := range parts {
		if p = strings.TrimSpace(p); p != "" {
			out = append(out, p)
		}
	}
	return out
}

func nowStr() string { return time.Now().Format("2006-01-02 15:04:05") }
