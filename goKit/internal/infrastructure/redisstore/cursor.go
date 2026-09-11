package redisstore

import (
	"context"
	"fmt"
	"time"

	"crawlkit/internal/domain/cursor"
)

// CursorExists 判断游标是否存在。
func (s *Store) CursorExists(ctx context.Context, cursorKey string) bool {
	n, _ := s.rdb.Exists(ctx, s.key(cursorKey)).Result()
	return n > 0
}

// InitCursor 初始化游标 Hash（幂等覆盖）。
func (s *Store) InitCursor(ctx context.Context, cursorKey string, meta map[string]any) error {
	fields := map[string]any{
		"status":      string(cursor.StatusPending),
		"done_pages":  "0",
		"total_pages": "0",
		"rows":        "0",
		"attempts":    "0",
		"updated_at":  nowStr(),
	}
	for k, v := range meta {
		fields[k] = v
	}
	return s.rdb.HSet(ctx, s.key(cursorKey), fields).Err()
}

// GetCursor 读取游标（不存在返回 nil）。
func (s *Store) GetCursor(ctx context.Context, cursorKey string) (*cursor.Cursor, error) {
	h, err := s.rdb.HGetAll(ctx, s.key(cursorKey)).Result()
	if err != nil {
		return nil, err
	}
	if len(h) == 0 {
		return nil, nil
	}
	return cursor.FromHash(h), nil
}

// PatchCursor 更新游标字段。
func (s *Store) PatchCursor(ctx context.Context, cursorKey string, fields map[string]any) error {
	if len(fields) == 0 {
		return nil
	}
	fields["updated_at"] = nowStr()
	return s.rdb.HSet(ctx, s.key(cursorKey), fields).Err()
}

// IncrCursorRows 累加游标 rows 字段，并刷新 updated_at。
func (s *Store) IncrCursorRows(ctx context.Context, cursorKey string, n int64) error {
	if n == 0 {
		return nil
	}
	if err := s.rdb.HIncrBy(ctx, s.key(cursorKey), "rows", n).Err(); err != nil {
		return err
	}
	return s.rdb.HSet(ctx, s.key(cursorKey), "updated_at", nowStr()).Err()
}

// TryLockNextPage 幂等“追加下一页”闸门：防止并发/重复投递造成同页任务堆积。
func (s *Store) TryLockNextPage(ctx context.Context, cursorKey string, page int) bool {
	lock := s.key(fmt.Sprintf("addlock:%s:%d", trimCursorPrefix(cursorKey), page))
	ok, err := s.rdb.SetNX(ctx, lock, "1", 60*time.Second).Result()
	return err == nil && ok
}

// ClearTaskLocks 清理某采集单元的追加锁（--force 重采时避免旧锁阻断翻页链）。
func (s *Store) ClearTaskLocks(ctx context.Context, cursorKey string) {
	base := trimCursorPrefix(cursorKey)
	for pg := 1; pg <= 200; pg++ {
		s.rdb.Del(ctx, s.key(fmt.Sprintf("addlock:%s:%d", base, pg)))
	}
}

// ListCursorKeys 列出全部游标 key（不含前缀）。
func (s *Store) ListCursorKeys(ctx context.Context) ([]string, error) {
	var out []string
	iter := s.rdb.Scan(ctx, 0, s.prefix+"cur:*", 500).Iterator()
	for iter.Next(ctx) {
		out = append(out, trimPrefix(iter.Val(), s.prefix))
	}
	return out, iter.Err()
}

func trimCursorPrefix(cursorKey string) string {
	if len(cursorKey) > 4 && cursorKey[:4] == "cur:" {
		return cursorKey[4:]
	}
	return cursorKey
}

func trimPrefix(s, prefix string) string {
	if len(prefix) <= len(s) && s[:len(prefix)] == prefix {
		return s[len(prefix):]
	}
	return s
}
