// crawler-scheduler 周期调度器：按固定间隔重复播种，实现定时/增量采集。
package main

import (
	"context"
	"flag"
	"os"
	"os/signal"
	"syscall"
	"time"

	"crawlkit/internal/appkit"
)

func main() {
	var (
		configPath string
		source     string
		units      string
		maxPages   int
		every      time.Duration
		once       bool
		force      bool
	)
	flag.StringVar(&configPath, "config", "", "配置文件路径")
	flag.StringVar(&source, "source", "", "数据源")
	flag.StringVar(&units, "units", "", "采集单元 id，逗号分隔")
	flag.IntVar(&maxPages, "max-pages", 0, "目标页上限")
	flag.DurationVar(&every, "every", 30*time.Minute, "调度间隔（如 30m / 1h）")
	flag.BoolVar(&once, "once", false, "只跑一次后退出")
	flag.BoolVar(&force, "force", false, "每次调度都重采（清理锁并重建游标）")
	flag.Parse()

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	_, eng, err := appkit.Load(ctx, configPath)
	if err != nil {
		appkit.Fail("%v", err)
	}
	defer eng.Close()

	producer := eng.Producer()
	runOnce := func() {
		res, err := producer.Seed(ctx, source, appkit.SplitCSV(units), force, maxPages)
		if err != nil {
			eng.Log.Error("调度播种失败: %v", err)
			return
		}
		eng.Log.Info("调度播种完成 job=%s units=%d queued=%d", res.JobID, res.Units, res.Queued)
	}

	runOnce()
	if once {
		return
	}
	if every <= 0 {
		every = 30 * time.Minute
	}
	ticker := time.NewTicker(every)
	defer ticker.Stop()
	eng.Log.Info("调度器常驻，间隔 %s", every)
	for {
		select {
		case <-ctx.Done():
			eng.Log.Info("调度器退出")
			return
		case <-ticker.C:
			runOnce()
		}
	}
}
