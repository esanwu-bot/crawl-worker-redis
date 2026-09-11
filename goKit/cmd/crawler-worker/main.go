// crawler-worker 消费命令：Redis Stream 消费组 Worker，可横向扩展多实例。
package main

import (
	"context"
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
		consumer   string
		idleRounds int
	)
	flag.StringVar(&configPath, "config", "", "配置文件路径")
	flag.StringVar(&consumer, "consumer", "", "消费者名（默认 worker-<pid>）；多实例务必各不相同")
	flag.IntVar(&idleRounds, "idle-rounds", 0, "连续空转多少轮后退出（0 => 配置值；负数 => 常驻）")
	flag.Parse()

	if consumer == "" {
		consumer = fmt.Sprintf("worker-%d", os.Getpid())
	}
	if idleRounds < 0 {
		idleRounds = -1 // 常驻
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	_, eng, err := appkit.Load(ctx, configPath)
	if err != nil {
		appkit.Fail("%v", err)
	}
	defer eng.Close()

	if err := eng.Worker(consumer).Run(ctx, idleRounds); err != nil {
		appkit.Fail("Worker 异常退出: %v", err)
	}
	fmt.Printf("[OK] worker %s 已退出\n", consumer)
}
