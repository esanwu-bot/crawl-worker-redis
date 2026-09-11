// Package appkit 是各命令入口的共享引导逻辑（配置加载 + 引擎装配 + 输出）。
package appkit

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"crawlkit/internal/config"
	"crawlkit/internal/crawler"
)

// SplitCSV 解析逗号分隔参数。
func SplitCSV(s string) []string {
	if strings.TrimSpace(s) == "" {
		return nil
	}
	parts := strings.Split(s, ",")
	out := make([]string, 0, len(parts))
	for _, p := range parts {
		if p = strings.TrimSpace(p); p != "" {
			out = append(out, p)
		}
	}
	return out
}

// LogDir 返回日志目录（与配置文件同级，缺省 ./logs）。
func LogDir(configPath string) string {
	if configPath != "" {
		return filepath.Join(filepath.Dir(configPath), "logs")
	}
	return "logs"
}

// Load 加载配置并装配引擎。
func Load(ctx context.Context, configPath string) (*config.Config, *crawler.Engine, error) {
	cfg, err := config.Load(configPath)
	if err != nil {
		return nil, nil, err
	}
	eng, err := crawler.New(ctx, cfg, LogDir(configPath))
	if err != nil {
		return nil, nil, err
	}
	return cfg, eng, nil
}

// Fail 打印错误并以非零码退出。
func Fail(format string, args ...any) {
	fmt.Fprintf(os.Stderr, "[FATAL] "+format+"\n", args...)
	os.Exit(1)
}
