// crawler-seed 播种命令：发现采集单元 -> 建作业 -> 投递首页任务。
package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"syscall"

	"crawlkit/internal/appkit"
)

func main() {
	var (
		configPath string
		source     string
		units      string
		force      bool
		maxPages   int
	)
	flag.StringVar(&configPath, "config", "", "配置文件路径（默认读 CW_CONFIG 或内置默认值）")
	flag.StringVar(&source, "source", "", "数据源（默认 config.default_source）")
	flag.StringVar(&units, "units", "", "采集单元 id，逗号分隔；为空则自动发现")
	flag.BoolVar(&force, "force", false, "重采：清理追加锁并重建游标")
	flag.IntVar(&maxPages, "max-pages", 0, "目标页上限（0 => config.seed.max_pages）")
	flag.Parse()

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	_, eng, err := appkit.Load(ctx, configPath)
	if err != nil {
		appkit.Fail("%v", err)
	}
	defer eng.Close()

	res, err := eng.Producer().Seed(ctx, source, appkit.SplitCSV(units), force, maxPages)
	if err != nil {
		appkit.Fail("播种失败: %v", err)
	}
	b, _ := json.MarshalIndent(res, "", "  ")
	fmt.Println(string(b))
}
