// crawler-api 提供 REST/Admin/Agent API：作业编排、监控、死信与结果查询。
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"crawlkit/internal/appkit"
	"crawlkit/internal/transport/httpapi"
)

func main() {
	var (
		configPath string
		addr       string
	)
	flag.StringVar(&configPath, "config", "", "配置文件路径")
	flag.StringVar(&addr, "addr", ":8088", "监听地址")
	flag.Parse()

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	_, eng, err := appkit.Load(ctx, configPath)
	if err != nil {
		appkit.Fail("%v", err)
	}
	defer eng.Close()

	srv := &http.Server{
		Addr:              addr,
		Handler:           httpapi.New(eng).Handler(),
		ReadHeaderTimeout: 10 * time.Second,
	}

	go func() {
		eng.Log.Info("API 服务启动 http://%s", addr)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			appkit.Fail("API 服务异常: %v", err)
		}
	}()

	<-ctx.Done()
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = srv.Shutdown(shutdownCtx)
	fmt.Println("[OK] API 服务已停止")
}
