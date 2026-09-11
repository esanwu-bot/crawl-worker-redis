package redisstore

import (
	"context"
	"fmt"
	"time"
)

// StatIncr 统计字段自增。
func (s *Store) StatIncr(ctx context.Context, field string, by int64) {
	s.rdb.HIncrBy(ctx, s.key(s.tc.StatsKey), field, by)
}

// Stats 读取全部统计字段。
func (s *Store) Stats(ctx context.Context) map[string]string {
	out, err := s.rdb.HGetAll(ctx, s.key(s.tc.StatsKey)).Result()
	if err != nil {
		return map[string]string{}
	}
	return out
}

// BumpAttempt 消息重试次数 +1，返回累计值（带 1 天过期）。
func (s *Store) BumpAttempt(ctx context.Context, msgID string) int64 {
	key := s.key("attempt:" + msgID)
	n, _ := s.rdb.Incr(ctx, key).Result()
	s.rdb.Expire(ctx, key, 24*time.Hour)
	return n
}

// DelAttempt 清除消息重试计数。
func (s *Store) DelAttempt(ctx context.Context, msgID string) error {
	return s.rdb.Del(ctx, s.key("attempt:"+msgID)).Err()
}

func nowStr() string { return time.Now().Format("2006-01-02 15:04:05") }

// Stat 汇总快照（供 stats 命令 / API metrics 使用）。
type Stat struct {
	StreamLen   int64             `json:"stream_len"`
	Pending     int64             `json:"pending"`
	DeadLetters int64             `json:"dead_letters"`
	Counters    map[string]string `json:"counters"`
}

// Snapshot 采集运行状态快照。
func (s *Store) Snapshot(ctx context.Context) Stat {
	return Stat{
		StreamLen:   s.StreamLen(ctx),
		Pending:     s.PendingTotal(ctx),
		DeadLetters: s.DeadLen(ctx),
		Counters:    s.Stats(ctx),
	}
}

// String 便于日志输出。
func (st Stat) String() string {
	return fmt.Sprintf("stream_len=%d pending=%d dead_letters=%d counters=%v",
		st.StreamLen, st.Pending, st.DeadLetters, st.Counters)
}
