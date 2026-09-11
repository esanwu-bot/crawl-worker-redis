// Package config 负责加载 crawler-engine 的统一配置。
//
// 口径与 PHP 版一致：默认值内置于代码，可由 configs/config.yaml 覆盖，
// 再被 CW_* 环境变量覆盖（环境变量优先级最高）。
package config

import (
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"gopkg.in/yaml.v3"
)

// FlexString 允许 YAML 中同一个字段既写整数也写字符串（如 unit_id: 7 / "7"）。
type FlexString string

// UnmarshalYAML 以节点原始文本赋值，兼容 int / string。
func (f *FlexString) UnmarshalYAML(value *yaml.Node) error {
	*f = FlexString(value.Value)
	return nil
}

// UnitConfig 是配置文件中静态声明的采集单元（如 MacCMS 分类）。
type UnitConfig struct {
	UnitID   FlexString     `yaml:"unit_id"`
	UnitName string         `yaml:"unit_name"`
	Params   map[string]any `yaml:"params"`
}

// SourceConfig 是单个数据源的声明（Source Definition）。
type SourceConfig struct {
	Adapter   string       `yaml:"adapter"`
	Site      string       `yaml:"site"`
	APIBase   string       `yaml:"api_base"`
	Type      int          `yaml:"type"`
	Entity    string       `yaml:"entity"`
	PageSize  int          `yaml:"page_size"`
	DetailURL string       `yaml:"detail_url"`
	Units     []UnitConfig `yaml:"units"`
}

// MySQLConfig 结果层连接参数。
type MySQLConfig struct {
	Host     string `yaml:"host"`
	Port     int    `yaml:"port"`
	User     string `yaml:"user"`
	Pass     string `yaml:"pass"`
	DB       string `yaml:"db"`
	Charset  string `yaml:"charset"`
	AltPorts []int  `yaml:"alt_ports"`
}

// RedisConfig 任务层连接参数。
type RedisConfig struct {
	Host        string `yaml:"host"`
	Port        int    `yaml:"port"`
	Auth        string `yaml:"auth"`
	Prefix      string `yaml:"prefix"`
	TimeoutMS   int    `yaml:"timeout_ms"`
	ClaimIdleMS int    `yaml:"claim_idle_ms"`
}

// ProxyConfig 代理池配置。
type ProxyConfig struct {
	Enabled        bool     `yaml:"enabled"`
	Mode           string   `yaml:"mode"`
	List           []string `yaml:"list"`
	Retries        int      `yaml:"retries"`
	CooldownMS     int      `yaml:"cooldown_ms"`
	DropAfterFails int      `yaml:"drop_after_fails"`
	Key            string   `yaml:"key"`
}

// HTTPConfig 抓取层配置。
type HTTPConfig struct {
	TimeoutSec       int         `yaml:"timeout_sec"`
	Retries          int         `yaml:"retries"`
	UA               string      `yaml:"ua"`
	Referer          string      `yaml:"referer"`
	DelayMS          int         `yaml:"delay_ms"`
	SSLVerify        bool        `yaml:"ssl_verify"`
	CABundle         string      `yaml:"ca_bundle"`
	InsecureFallback bool        `yaml:"insecure_fallback"`
	Proxy            ProxyConfig `yaml:"proxy"`
}

// TaskConfig 任务语义配置。
type TaskConfig struct {
	Stream      string `yaml:"stream"`
	Group       string `yaml:"group"`
	DeadStream  string `yaml:"dead_stream"`
	StatsKey    string `yaml:"stats_key"`
	PageLimit   int    `yaml:"page_limit"`
	MaxAttempts int    `yaml:"max_attempts"`
}

// SeedConfig 播种默认值。
type SeedConfig struct {
	Limit    int `yaml:"limit"`
	MaxPages int `yaml:"max_pages"`
}

// WorkerConfig Worker 运行参数。
type WorkerConfig struct {
	Batch      int `yaml:"batch"`
	BlockSec   int `yaml:"block_sec"`
	IdleRounds int `yaml:"idle_rounds"`
}

// Config 是完整配置。
type Config struct {
	DefaultSource string                  `yaml:"default_source"`
	Sources       map[string]SourceConfig `yaml:"sources"`
	MySQL         MySQLConfig             `yaml:"mysql"`
	Redis         RedisConfig             `yaml:"redis"`
	HTTP          HTTPConfig              `yaml:"http"`
	Task          TaskConfig              `yaml:"task"`
	Seed          SeedConfig              `yaml:"seed"`
	Worker        WorkerConfig            `yaml:"worker"`
}

// Default 返回内置默认配置（与 configs/config.yaml 保持一致）。
func Default() *Config {
	return &Config{
		DefaultSource: "shikues",
		Sources: map[string]SourceConfig{
			"shikues": {
				Adapter: "shikues",
				Site:    "https://www.shikues.com",
				APIBase: "https://api.shikues.com/api/product",
				Type:    1,
				Entity:  "model",
			},
			"maccms": {
				Adapter:   "maccms",
				Site:      "https://cj.lziapi.com",
				APIBase:   "https://cj.lziapi.com/api.php/provide/vod/",
				Entity:    "vod",
				PageSize:  20,
				DetailURL: "/api.php/provide/vod/?ac=detail&ids={id}",
				Units: []UnitConfig{
					{UnitID: "7", UnitName: "喜剧片"},
					{UnitID: "15", UnitName: "韩国剧"},
					{UnitID: "30", UnitName: "日韩动漫"},
				},
			},
		},
		MySQL: MySQLConfig{
			Host: "127.0.0.1", Port: 3306, User: "root", Pass: "root",
			DB: "shikues_crawler", Charset: "utf8mb4", AltPorts: []int{3307, 3308},
		},
		Redis: RedisConfig{
			Host: "127.0.0.1", Port: 6379, Prefix: "cw:", TimeoutMS: 5000, ClaimIdleMS: 30000,
		},
		HTTP: HTTPConfig{
			TimeoutSec: 20, Retries: 2,
			UA:               "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36",
			Referer:          "https://www.shikues.com/categories/",
			DelayMS:          200,
			SSLVerify:        true,
			InsecureFallback: true,
			Proxy: ProxyConfig{
				Enabled: false, Mode: "pool", Retries: 2,
				CooldownMS: 30000, DropAfterFails: 3, Key: "proxy:pool",
			},
		},
		Task: TaskConfig{
			Stream: "tasks", Group: "workers", DeadStream: "tasks:dead",
			StatsKey: "stats", PageLimit: 15, MaxAttempts: 3,
		},
		Seed:   SeedConfig{Limit: 3, MaxPages: 3},
		Worker: WorkerConfig{Batch: 5, BlockSec: 5, IdleRounds: 30},
	}
}

// Load 读取配置文件（可为空字符串 => 仅用默认值 + 环境变量）。
//
// 优先级（低 → 高）：内置默认值 < configs/config.yaml < .env.local < CW_* 真实环境变量。
// 与 PHP 版一致：真实 shell 环境变量优先，.env.local 仅做兜底，避免误覆盖命令行注入。
func Load(path string) (*Config, error) {
	loadDotEnv(path)

	cfg := Default()
	if path == "" {
		path = os.Getenv("CW_CONFIG")
	}
	if path != "" {
		raw, err := os.ReadFile(path)
		if err != nil {
			return nil, fmt.Errorf("读取配置文件失败 %s: %w", path, err)
		}
		if err := yaml.Unmarshal(raw, cfg); err != nil {
			return nil, fmt.Errorf("解析配置文件失败 %s: %w", path, err)
		}
	}
	cfg.applyEnv()
	cfg.normalize()
	return cfg, nil
}

// loadDotEnv 查找并加载 .env.local（仓库惯例：真实连接凭据放这里，已 gitignore）。
//
// 查找顺序：CW_ENV_FILE > 当前目录 > 配置文件同级 / 上级 / 上上级 > 再上级仓库根。
// 只有未被真实环境变量占用的键才会写入，保证 shell 注入优先。
func loadDotEnv(configPath string) {
	candidates := []string{}
	if v := os.Getenv("CW_ENV_FILE"); v != "" {
		candidates = append(candidates, v)
	}
	candidates = append(candidates, ".env.local", filepath.Join("..", ".env.local"), filepath.Join("..", "..", ".env.local"))
	if configPath != "" {
		dir := filepath.Dir(configPath)
		candidates = append(candidates,
			filepath.Join(dir, ".env.local"),
			filepath.Join(dir, "..", ".env.local"),
			filepath.Join(dir, "..", "..", ".env.local"),
		)
	}
	for _, p := range candidates {
		raw, err := os.ReadFile(p)
		if err != nil {
			continue
		}
		for _, line := range strings.Split(string(raw), "\n") {
			line = strings.TrimSpace(line)
			if line == "" || strings.HasPrefix(line, "#") {
				continue
			}
			eq := strings.Index(line, "=")
			if eq <= 0 {
				continue
			}
			k := strings.TrimSpace(line[:eq])
			v := strings.Trim(strings.TrimSpace(line[eq+1:]), "\"'")
			if k == "" || v == "" {
				continue
			}
			if os.Getenv(k) == "" { // 真实环境变量优先
				_ = os.Setenv(k, v)
			}
		}
		return // 只加载第一个命中的文件
	}
}

// applyEnv 用 CW_* 环境变量覆盖（非空才覆盖）。
func (c *Config) applyEnv() {
	c.DefaultSource = env("CW_SOURCE", c.DefaultSource)

	c.MySQL.Host = env("CW_MYSQL_HOST", c.MySQL.Host)
	c.MySQL.Port = envInt("CW_MYSQL_PORT", c.MySQL.Port)
	c.MySQL.User = env("CW_MYSQL_USER", c.MySQL.User)
	c.MySQL.Pass = env("CW_MYSQL_PASS", c.MySQL.Pass)
	c.MySQL.DB = env("CW_MYSQL_DB", c.MySQL.DB)

	c.Redis.Host = env("CW_REDIS_HOST", c.Redis.Host)
	c.Redis.Port = envInt("CW_REDIS_PORT", c.Redis.Port)
	c.Redis.Auth = env("CW_REDIS_AUTH", c.Redis.Auth)
	c.Redis.Prefix = env("CW_REDIS_PREFIX", c.Redis.Prefix)
	c.Redis.ClaimIdleMS = envInt("CW_CLAIM_IDLE_MS", c.Redis.ClaimIdleMS)

	c.HTTP.DelayMS = envInt("CW_DELAY_MS", c.HTTP.DelayMS)
	c.HTTP.SSLVerify = envBool("CW_SSL_VERIFY", c.HTTP.SSLVerify)
	c.HTTP.CABundle = env("CW_CA_BUNDLE", c.HTTP.CABundle)
	c.Seed.MaxPages = envInt("CW_MAX_PAGES", c.Seed.MaxPages)

	c.HTTP.Proxy.Enabled = envBool("CW_PROXY_ENABLED", c.HTTP.Proxy.Enabled)
	c.HTTP.Proxy.Mode = env("CW_PROXY_MODE", c.HTTP.Proxy.Mode)
	if v, ok := os.LookupEnv("CW_PROXY_LIST"); ok {
		c.HTTP.Proxy.List = splitCSV(v)
	}

	// 逐源环境变量覆盖
	if s, ok := c.Sources["shikues"]; ok {
		s.APIBase = env("CW_API_BASE", s.APIBase)
		c.Sources["shikues"] = s
	}
	if s, ok := c.Sources["maccms"]; ok {
		s.Site = env("CW_MACCMS_SITE", s.Site)
		s.APIBase = env("CW_MACCMS_API_BASE", s.APIBase)
		s.PageSize = envInt("CW_MACCMS_PAGE_SIZE", s.PageSize)
		c.Sources["maccms"] = s
	}
}

// normalize 补齐缺失的零值字段，保证下游拿到可用配置。
func (c *Config) normalize() {
	d := Default()
	if c.Redis.Prefix == "" {
		c.Redis.Prefix = d.Redis.Prefix
	}
	if c.Redis.TimeoutMS <= 0 {
		c.Redis.TimeoutMS = d.Redis.TimeoutMS
	}
	if c.Redis.ClaimIdleMS <= 0 {
		c.Redis.ClaimIdleMS = d.Redis.ClaimIdleMS
	}
	if c.Task.Stream == "" {
		c.Task.Stream = d.Task.Stream
	}
	if c.Task.Group == "" {
		c.Task.Group = d.Task.Group
	}
	if c.Task.DeadStream == "" {
		c.Task.DeadStream = d.Task.DeadStream
	}
	if c.Task.StatsKey == "" {
		c.Task.StatsKey = d.Task.StatsKey
	}
	if c.Task.PageLimit <= 0 {
		c.Task.PageLimit = d.Task.PageLimit
	}
	if c.Task.MaxAttempts <= 0 {
		c.Task.MaxAttempts = d.Task.MaxAttempts
	}
	if c.Worker.Batch <= 0 {
		c.Worker.Batch = d.Worker.Batch
	}
	if c.Worker.BlockSec <= 0 {
		c.Worker.BlockSec = d.Worker.BlockSec
	}
	if c.Seed.Limit <= 0 {
		c.Seed.Limit = d.Seed.Limit
	}
	if c.Seed.MaxPages <= 0 {
		c.Seed.MaxPages = d.Seed.MaxPages
	}
	if c.HTTP.TimeoutSec <= 0 {
		c.HTTP.TimeoutSec = d.HTTP.TimeoutSec
	}
	if c.HTTP.Retries < 0 {
		c.HTTP.Retries = d.HTTP.Retries
	}
	if c.HTTP.Proxy.Key == "" {
		c.HTTP.Proxy.Key = d.HTTP.Proxy.Key
	}
	if c.MySQL.Charset == "" {
		c.MySQL.Charset = d.MySQL.Charset
	}
	if c.MySQL.AltPorts == nil {
		c.MySQL.AltPorts = d.MySQL.AltPorts
	}
	// 逐源补齐 adapter/entity 缺省
	for name, s := range c.Sources {
		if s.Adapter == "" {
			s.Adapter = name
		}
		if s.Entity == "" {
			if d2, ok := d.Sources[name]; ok {
				s.Entity = d2.Entity
			}
		}
		if s.PageSize <= 0 {
			if d2, ok := d.Sources[name]; ok && d2.PageSize > 0 {
				s.PageSize = d2.PageSize
			}
		}
		c.Sources[name] = s
	}
}

func env(key, def string) string {
	if v, ok := os.LookupEnv(key); ok && v != "" {
		return v
	}
	return def
}

func envInt(key string, def int) int {
	if v, ok := os.LookupEnv(key); ok && v != "" {
		if n, err := strconv.Atoi(strings.TrimSpace(v)); err == nil {
			return n
		}
	}
	return def
}

func envBool(key string, def bool) bool {
	if v, ok := os.LookupEnv(key); ok && v != "" {
		switch strings.ToLower(strings.TrimSpace(v)) {
		case "1", "true", "yes", "on":
			return true
		case "0", "false", "no", "off":
			return false
		}
	}
	return def
}

func splitCSV(v string) []string {
	parts := strings.Split(v, ",")
	out := make([]string, 0, len(parts))
	for _, p := range parts {
		if p = strings.TrimSpace(p); p != "" {
			out = append(out, p)
		}
	}
	return out
}
