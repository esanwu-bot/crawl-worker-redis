// crawler-cli 运维命令行：initdb / stats / sources / dead / requeue / reset。
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"

	"crawlkit/internal/appkit"
)

func main() {
	// 自定义解析：同时支持 `crawler-cli initdb -config x` 与 `crawler-cli -config x initdb`。
	configPath, limit, yes, cmd := parseArgs(os.Args[1:])
	if cmd == "" {
		usage()
		os.Exit(2)
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	_, eng, err := appkit.Load(ctx, configPath)
	if err != nil {
		appkit.Fail("%v", err)
	}
	defer eng.Close()

	switch cmd {
	case "initdb":
		types, _ := eng.DB.CountSourceTypes(ctx)
		records, _ := eng.DB.CountRecords(ctx, "")
		jobs, _ := eng.DB.CountJobs(ctx, "")
		fmt.Printf("[OK] 建库建表完成：source_types=%d crawl_jobs=%d crawl_records=%d\n", types, jobs, records)

	case "stats":
		snap := eng.Store.Snapshot(ctx)
		byEntity, _ := eng.DB.RecordsByEntity(ctx)
		fmt.Printf("stream_len   : %d\n", snap.StreamLen)
		fmt.Printf("pending(PEL) : %d\n", snap.Pending)
		fmt.Printf("dead_letters : %d\n", snap.DeadLetters)
		fmt.Println("counters     :")
		for k, v := range snap.Counters {
			fmt.Printf("  %-14s %s\n", k, v)
		}
		fmt.Println("records      :")
		for _, e := range byEntity {
			fmt.Printf("  %-10s %-12s %d\n", e.Source, e.Entity, e.Count)
		}

	case "sources":
		for _, name := range eng.Adapters.Sources() {
			fmt.Printf("- %s (adapter=%s entity=%s)\n", name, eng.Cfg.Sources[name].Adapter, eng.Cfg.Sources[name].Entity)
		}
		rows, _ := eng.DB.ListSourceTypes(ctx, "")
		fmt.Printf("目录条目 %d 条：\n", len(rows))
		for _, r := range rows {
			fmt.Printf("  %-10s %-6d %s / %s\n", r.Source, r.TypeID, r.CnName, r.EnName)
		}

	case "dead":
		items, err := eng.Store.ReadDead(ctx, limit)
		if err != nil {
			appkit.Fail("%v", err)
		}
		b, _ := json.MarshalIndent(items, "", "  ")
		fmt.Println(string(b))

	case "requeue":
		moved, err := eng.Store.RequeueDead(ctx, nil, limit)
		if err != nil {
			appkit.Fail("%v", err)
		}
		fmt.Printf("[OK] 已重新投递 %d 条死信\n", moved)

	case "reset":
		if !yes {
			appkit.Fail("reset 会清空任务流/游标/统计，请加 --yes 确认")
		}
		if err := eng.Store.ResetState(ctx); err != nil {
			appkit.Fail("%v", err)
		}
		fmt.Println("[OK] 已清理 Redis 任务状态（任务流/死信/游标/统计/重试计数）")

	default:
		usage()
		os.Exit(2)
	}
}

// parseArgs 同时容忍「命令在前」与「flag 在前」两种写法。
func parseArgs(args []string) (configPath string, limit int, yes bool, cmd string) {
	limit = 20
	for i := 0; i < len(args); i++ {
		a := args[i]
		name, inline, hasInline := splitFlag(a)
		switch name {
		case "--config", "-config":
			if hasInline {
				configPath = inline
			} else if i+1 < len(args) {
				configPath = args[i+1]
				i++
			}
		case "--limit", "-limit":
			v := inline
			if !hasInline && i+1 < len(args) {
				v = args[i+1]
				i++
			}
			if n, err := strconv.Atoi(strings.TrimSpace(v)); err == nil {
				limit = n
			}
		case "--yes", "-yes":
			yes = true
		case "":
			if cmd == "" {
				cmd = a
			}
		default:
			// 未知 flag 忽略
		}
	}
	return
}

// splitFlag 拆分 `--k=v` / `--k`；非 flag 返回空名。
func splitFlag(a string) (name, value string, hasValue bool) {
	if !strings.HasPrefix(a, "-") {
		return "", "", false
	}
	if i := strings.Index(a, "="); i >= 0 {
		return a[:i], a[i+1:], true
	}
	return a, "", false
}

func usage() {
	fmt.Println(`crawler-cli <command> [flags]

命令:
  initdb    建库建表（幂等）并输出表计数
  stats     输出任务流/PEL/死信/统计/记录分布
  sources   列出已注册数据源与采集单元目录
  dead      列出死信消息
  requeue   将死信重新投递回任务流
  reset     清空 Redis 任务状态（需 --yes）

通用 flags:
  --config   配置文件路径
  --limit    条数上限（dead/requeue）`)
}
