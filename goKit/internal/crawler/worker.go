package crawler

import (
	"context"
	"errors"
	"time"

	"crawlkit/internal/adapter"
	"crawlkit/internal/config"
	"crawlkit/internal/domain/cursor"
	"crawlkit/internal/domain/source"
	"crawlkit/internal/domain/task"
	"crawlkit/internal/infrastructure/mysqlstore"
	"crawlkit/internal/infrastructure/observability"
	"crawlkit/internal/infrastructure/redisstore"
)

// Worker 消费者主循环：
//
//	接管超时消息(ClaimBatch) -> 补读新消息(ReadBatch) -> 执行 -> ACK / 重试 / 死信
//
// 失败消息不 ACK，留在 PEL 中，等待下次被接管重试（与 PHP 版语义一致）。
type Worker struct {
	cfg      *config.Config
	store    *redisstore.Store
	db       *mysqlstore.Store
	adapters *adapter.Registry
	log      *observability.Logger
	consumer string
}

// Run 启动主循环。idleRounds>0 时连续空转达到阈值后自动退出（便于离线/批处理）。
func (w *Worker) Run(ctx context.Context, idleRounds int) error {
	if idleRounds == 0 {
		idleRounds = w.cfg.Worker.IdleRounds
	}
	w.log.Info("Worker 启动 consumer=%s group=%s stream=%s idle_rounds=%d",
		w.consumer, w.cfg.Task.Group, w.cfg.Task.Stream, idleRounds)

	idle := 0
	for {
		select {
		case <-ctx.Done():
			w.log.Info("Worker 收到停止信号，优雅退出 consumer=%s", w.consumer)
			return nil
		default:
		}

		msgs, err := w.store.ClaimBatch(ctx, w.consumer, w.cfg.Worker.Batch)
		if err != nil && !errors.Is(err, context.Canceled) {
			w.log.Warn("接管超时消息失败: %v", err)
		}
		if len(msgs) == 0 {
			msgs, err = w.store.ReadBatch(ctx, w.consumer, w.cfg.Worker.Batch, w.cfg.Worker.BlockSec)
			if err != nil {
				if errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
					continue
				}
				w.log.Warn("读取任务失败: %v", err)
				time.Sleep(time.Second)
			}
		}

		if len(msgs) == 0 {
			idle++
			w.store.StatIncr(ctx, "idle", 1)
			if idleRounds > 0 && idle >= idleRounds {
				w.log.Info("Worker 连续空转 %d 轮，自动退出 consumer=%s", idle, w.consumer)
				return nil
			}
			continue
		}
		idle = 0
		w.store.StatIncr(ctx, "loop", 1)

		for _, msg := range msgs {
			w.process(ctx, msg)
		}
	}
}

func (w *Worker) process(ctx context.Context, msg redisstore.Message) {
	if msg.Payload == nil {
		w.log.Warn("消息载荷无法解析，丢弃 id=%s raw=%s", msg.ID, truncate(msg.Raw, 200))
		w.store.StatIncr(ctx, "invalid", 1)
		_ = w.store.Ack(ctx, msg.ID)
		return
	}
	t := msg.Payload
	if !t.Valid() {
		_ = w.store.AddDead(ctx, t, "非法任务载荷")
		w.store.StatIncr(ctx, "dead", 1)
		_ = w.store.Ack(ctx, msg.ID)
		return
	}

	// 作业级控制：取消 -> 直接收尾；暂停 -> 暂存待恢复。
	switch w.store.GetJobState(ctx, t.JobID) {
	case redisstore.StateCancelled:
		w.patchCursor(ctx, t, map[string]any{"status": string(cursor.StatusCancelled)})
		w.store.StatIncr(ctx, "page_cancelled", 1)
		_ = w.store.Ack(ctx, msg.ID)
		w.log.Info("作业已取消，跳过 %s", t.Describe())
		return
	case redisstore.StatePaused:
		if err := w.store.ParkTask(ctx, t.JobID, msg.Raw); err == nil {
			w.store.StatIncr(ctx, "page_parked", 1)
			_ = w.store.Ack(ctx, msg.ID)
		}
		return
	}

	ad, err := w.adapters.Get(t.Source)
	if err != nil {
		w.log.Error("找不到适配器 %s，转死信", t.Source)
		_ = w.store.AddDead(ctx, t, err.Error())
		w.store.StatIncr(ctx, "dead", 1)
		_ = w.store.Ack(ctx, msg.ID)
		return
	}

	// 注入分页大小（站点每页行数由 Runtime 统一控制）
	t.Params = copyParams(t.Params, w.cfg.Task.PageLimit)

	// 游标兜底初始化
	if !w.store.CursorExists(ctx, t.CursorKey()) {
		_ = w.store.InitCursor(ctx, t.CursorKey(), map[string]any{
			"source":    t.Source,
			"entity":    t.Entity,
			"unit_id":   t.Cursor.UnitID,
			"unit_name": t.Cursor.UnitName,
		})
	}

	// 单次任务超时控制：避免单条任务无限阻塞整个 Worker。
	execCtx := ctx
	cancel := func() {}
	if w.cfg.HTTP.TimeoutSec > 0 {
		execCtx, cancel = context.WithTimeout(ctx, time.Duration(w.cfg.HTTP.TimeoutSec*w.cfg.HTTP.Retries+15)*time.Second)
	}
	defer cancel()

	start := time.Now()
	result, err := ad.ExecuteList(execCtx, t)
	elapsed := time.Since(start)
	if err != nil {
		w.onFail(ctx, t, msg.ID, err)
		return
	}

	w.onSuccess(ctx, t, msg.ID, result, elapsed)
}

func (w *Worker) onSuccess(ctx context.Context, t *task.Payload, msgID string, res *source.ListResult, elapsed time.Duration) {
	totalPages := t.TargetPagesOr(w.cfg.Seed.MaxPages)
	ended := res.Ended || len(res.Records) == 0 || (totalPages > 0 && t.Cursor.Page >= totalPages)

	rows := 0
	if len(res.Records) > 0 {
		n, err := w.db.UpsertRecords(ctx, res.Records)
		if err != nil {
			// 落库失败按可重试处理（保留 PEL，稍后接管）
			w.log.Error("落库失败 %s: %v", t.Describe(), err)
			w.onFail(ctx, t, msgID, err)
			return
		}
		rows = n
	}

	w.patchCursor(ctx, t, map[string]any{
		"status":      string(cursor.StatusActive),
		"total_pages": res.LastPage,
		"rows":        rows, // 由 store 侧做 HINCRBY，见 patchCursor 说明
	})
	w.store.StatIncr(ctx, "page_ok", 1)
	w.store.StatIncr(ctx, "rows", int64(rows))

	if ended {
		// 以游标累计 rows 回写作业，避免只统计末页。
		unitRows := rows
		if cur, err := w.store.GetCursor(ctx, t.CursorKey()); err == nil && cur != nil && cur.Rows > 0 {
			unitRows = cur.Rows
		}
		_ = w.db.UnitFinished(ctx, t.JobID, true, unitRows)
		if err := w.patchCursor(ctx, t, map[string]any{"status": string(cursor.StatusDone)}); err != nil {
			w.log.Warn("写游标失败 %s: %v", t.Describe(), err)
		}
		w.store.StatIncr(ctx, "unit_done", 1)
		_ = w.store.DelAttempt(ctx, msgID)
		_ = w.store.Ack(ctx, msgID)
		w.log.Info("单元完成 %s rows=%d elapsed=%.2fs", t.Describe(), unitRows, elapsed.Seconds())
		return
	}

	// 追加下一页（加锁防重复投递）
	next := t.Next()
	if w.store.TryLockNextPage(ctx, t.CursorKey(), t.Cursor.Page) {
		if _, err := w.store.PushTask(ctx, next); err != nil {
			w.log.Error("追加下一页失败 %s: %v", next.Describe(), err)
		}
	}
	_ = w.store.DelAttempt(ctx, msgID)
	_ = w.store.Ack(ctx, msgID)
	w.log.Info("翻页成功 %s rows=%d last_page=%d elapsed=%.2fs",
		t.Describe(), rows, res.LastPage, elapsed.Seconds())
}

func (w *Worker) onFail(ctx context.Context, t *task.Payload, msgID string, execErr error) {
	msgAttempts := int(w.store.BumpAttempt(ctx, msgID))
	cur, _ := w.store.GetCursor(ctx, t.CursorKey())
	cursorAttempts := 0
	if cur != nil {
		cursorAttempts = cur.Attempts
	}
	totalAttempts := msgAttempts + cursorAttempts

	if totalAttempts >= w.cfg.Task.MaxAttempts {
		reason := truncate(execErr.Error(), 200)
		_ = w.store.AddDead(ctx, t, reason)
		_ = w.store.Ack(ctx, msgID)
		_ = w.store.DelAttempt(ctx, msgID)
		_ = w.db.UnitFinished(ctx, t.JobID, false, 0)
		w.patchCursor(ctx, t, map[string]any{
			"status":   string(cursor.StatusDead),
			"attempts": totalAttempts,
			"last_err": reason,
		})
		w.store.StatIncr(ctx, "page_dead", 1)
		w.store.StatIncr(ctx, "unit_dead", 1)
		w.log.Error("超过最大重试(%d)，转死信 %s reason=%s", w.cfg.Task.MaxAttempts, t.Describe(), reason)
		return
	}

	w.patchCursor(ctx, t, map[string]any{
		"status":   string(cursor.StatusActive),
		"attempts": totalAttempts,
		"last_err": truncate(execErr.Error(), 200),
	})
	w.store.StatIncr(ctx, "page_retry", 1)
	w.log.Warn("执行失败(第 %d 次) %s err=%v — 保留 PEL 待接管重试", totalAttempts, t.Describe(), execErr)
}

// patchCursor 更新游标；rows 字段以累加语义写入，避免覆盖历史计数。
func (w *Worker) patchCursor(ctx context.Context, t *task.Payload, fields map[string]any) error {
	rowsDelta, hasRows := fields["rows"]
	delete(fields, "rows")
	if err := w.store.PatchCursor(ctx, t.CursorKey(), fields); err != nil {
		return err
	}
	if hasRows {
		if n, ok := rowsDelta.(int); ok && n != 0 {
			return w.store.IncrCursorRows(ctx, t.CursorKey(), int64(n))
		}
	}
	return nil
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "..."
}
