package config

import (
	"os"
	"path/filepath"
	"testing"
)

func TestDefaultIsUsable(t *testing.T) {
	cfg := Default()
	if cfg.DefaultSource != "shikues" {
		t.Fatalf("默认数据源应为 shikues，实际 %s", cfg.DefaultSource)
	}
	if len(cfg.Sources) != 2 {
		t.Fatalf("应内置 shikues/maccms 两个数据源，实际 %d", len(cfg.Sources))
	}
	if cfg.Task.MaxAttempts != 3 || cfg.Task.PageLimit != 15 {
		t.Fatalf("任务默认值不符: %+v", cfg.Task)
	}
}

func TestLoadFromFileAndEnvOverride(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "config.yaml")
	yaml := `
default_source: maccms
mysql:
  host: 10.0.0.1
  port: 3307
  user: app
  pass: secret
  db: crawler
redis:
  host: 10.0.0.2
  port: 6380
  prefix: "cwx:"
seed:
  limit: 9
  max_pages: 8
`
	if err := os.WriteFile(path, []byte(yaml), 0o644); err != nil {
		t.Fatal(err)
	}

	cfg, err := Load(path)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.MySQL.Host != "10.0.0.1" || cfg.MySQL.DB != "crawler" {
		t.Fatalf("文件配置未生效: %+v", cfg.MySQL)
	}
	if cfg.Redis.Prefix != "cwx:" {
		t.Fatalf("redis prefix 不符: %s", cfg.Redis.Prefix)
	}
	if cfg.Seed.Limit != 9 || cfg.Seed.MaxPages != 8 {
		t.Fatalf("seed 配置未生效: %+v", cfg.Seed)
	}
	// 未在文件中覆盖的 sources 应保留默认目录，并补齐 adapter/entity
	if cfg.Sources["shikues"].Entity != "model" {
		t.Fatalf("默认 sources 应保留: %+v", cfg.Sources["shikues"])
	}

	t.Setenv("CW_MYSQL_HOST", "127.0.0.9")
	t.Setenv("CW_MAX_PAGES", "2")
	t.Setenv("CW_REDIS_PREFIX", "env:")
	cfg2, err := Load(path)
	if err != nil {
		t.Fatal(err)
	}
	if cfg2.MySQL.Host != "127.0.0.9" {
		t.Fatalf("环境变量应覆盖文件: %s", cfg2.MySQL.Host)
	}
	if cfg2.Seed.MaxPages != 2 {
		t.Fatalf("CW_MAX_PAGES 应覆盖: %d", cfg2.Seed.MaxPages)
	}
	if cfg2.Redis.Prefix != "env:" {
		t.Fatalf("CW_REDIS_PREFIX 应覆盖: %s", cfg2.Redis.Prefix)
	}
}

func TestFlexStringUnitID(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "c.yaml")
	yaml := `
sources:
  maccms:
    adapter: maccms
    units:
      - unit_id: 7
        unit_name: 喜剧片
      - unit_id: "15"
        unit_name: 韩国剧
`
	if err := os.WriteFile(path, []byte(yaml), 0o644); err != nil {
		t.Fatal(err)
	}
	cfg, err := Load(path)
	if err != nil {
		t.Fatal(err)
	}
	units := cfg.Sources["maccms"].Units
	if len(units) != 2 {
		t.Fatalf("应解析 2 个单元，实际 %d", len(units))
	}
	if string(units[0].UnitID) != "7" || string(units[1].UnitID) != "15" {
		t.Fatalf("unit_id 应同时兼容 int/string: %+v", units)
	}
}
