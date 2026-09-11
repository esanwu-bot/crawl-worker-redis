package redisstore

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	"github.com/redis/go-redis/v9"

	"crawlkit/internal/domain/task"
)

// Message 是一条从 Stream 读到的消息。
type Message struct {
	ID      string
	Payload *task.Payload
	Raw     string
}

// PushTask 投递一条通用任务；消息体统一 JSON 打包到 p 字段。
func (s *Store) PushTask(ctx context.Context, p *task.Payload) (string, error) {
	b, err := p.Encode()
	if err != nil {
		return "", err
	}
	return s.PushRaw(ctx, string(b))
}

// PushRaw 直接投递已序列化的载荷 JSON。
func (s *Store) PushRaw(ctx context.Context, payloadJSON string) (string, error) {
	id, err := s.rdb.XAdd(ctx, &redis.XAddArgs{
		Stream: s.key(s.tc.Stream),
		MaxLen: s.tc.MaxLen,
		Approx: true,
		Values: map[string]any{"p": payloadJSON},
	}).Result()
	if err != nil {
		return "", fmt.Errorf("XADD %s 失败: %w", s.tc.Stream, err)
	}
	return id, nil
}

// AddDead 写入死信流。
func (s *Store) AddDead(ctx context.Context, p *task.Payload, reason string) error {
	raw := map[string]any{}
	if b, err := p.Encode(); err == nil {
		_ = json.Unmarshal(b, &raw)
	}
	raw["_dead_reason"] = reason
	raw["_dead_at"] = nowStr()
	b, _ := json.Marshal(raw)
	_, err := s.rdb.XAdd(ctx, &redis.XAddArgs{
		Stream: s.key(s.tc.DeadStream),
		MaxLen: s.tc.MaxLen,
		Approx: true,
		Values: map[string]any{"p": string(b)},
	}).Result()
	return err
}

// ReadBatch 阻塞读取新消息（'>'）。
func (s *Store) ReadBatch(ctx context.Context, consumer string, count, blockSec int) ([]Message, error) {
	if blockSec < 0 {
		blockSec = 0
	}
	res, err := s.rdb.XReadGroup(ctx, &redis.XReadGroupArgs{
		Group:    s.tc.Group,
		Consumer: consumer,
		Streams:  []string{s.key(s.tc.Stream), ">"},
		Count:    int64(count),
		Block:    time.Duration(blockSec) * time.Second,
	}).Result()
	if err != nil {
		if err == redis.Nil {
			return nil, nil
		}
		return nil, err
	}
	return flatten(res), nil
}

// ClaimBatch 接管超过 claim_idle 的失联消息（崩溃恢复 / 失败重试）。
//
// 使用 XPENDING IDLE 定位 + XCLAIM 转移的等价实现，两个命令均为 Redis 5.0 基础能力。
func (s *Store) ClaimBatch(ctx context.Context, consumer string, count int) ([]Message, error) {
	full := s.key(s.tc.Stream)
	pending, err := s.rdb.XPendingExt(ctx, &redis.XPendingExtArgs{
		Stream: full,
		Group:  s.tc.Group,
		Idle:   s.claimIdle,
		Start:  "-",
		End:    "+",
		Count:  int64(count),
	}).Result()
	if err != nil {
		if err == redis.Nil {
			return nil, nil
		}
		return nil, err
	}
	if len(pending) == 0 {
		return nil, nil
	}
	ids := make([]string, 0, len(pending))
	for _, p := range pending {
		if p.ID != "" {
			ids = append(ids, p.ID)
		}
	}
	if len(ids) == 0 {
		return nil, nil
	}
	claimed, err := s.rdb.XClaim(ctx, &redis.XClaimArgs{
		Stream:   full,
		Group:    s.tc.Group,
		Consumer: consumer,
		MinIdle:  s.claimIdle,
		Messages: ids,
	}).Result()
	if err != nil {
		return nil, err
	}
	out := make([]Message, 0, len(claimed))
	for _, m := range claimed {
		if msg, ok := toMessage(m); ok {
			out = append(out, msg)
		}
	}
	return out, nil
}

// Ack 确认消息。
func (s *Store) Ack(ctx context.Context, ids ...string) error {
	if len(ids) == 0 {
		return nil
	}
	return s.rdb.XAck(ctx, s.key(s.tc.Stream), s.tc.Group, ids...).Err()
}

// StreamLen 任务流长度。
func (s *Store) StreamLen(ctx context.Context) int64 {
	n, _ := s.rdb.XLen(ctx, s.key(s.tc.Stream)).Result()
	return n
}

// DeadLen 死信流长度。
func (s *Store) DeadLen(ctx context.Context) int64 {
	n, _ := s.rdb.XLen(ctx, s.key(s.tc.DeadStream)).Result()
	return n
}

// PendingTotal PEL 中待确认消息数。
func (s *Store) PendingTotal(ctx context.Context) int64 {
	info, err := s.rdb.XPending(ctx, s.key(s.tc.Stream), s.tc.Group).Result()
	if err != nil || info == nil {
		return 0
	}
	return info.Count
}

// DeadMessage 死信流中的一条消息。
type DeadMessage struct {
	ID      string         `json:"id"`
	Payload map[string]any `json:"payload"`
}

// ReadDead 读取死信清单（最新优先）。
func (s *Store) ReadDead(ctx context.Context, limit int) ([]DeadMessage, error) {
	if limit <= 0 {
		limit = 20
	}
	msgs, err := s.rdb.XRevRangeN(ctx, s.key(s.tc.DeadStream), "+", "-", int64(limit)).Result()
	if err != nil {
		return nil, err
	}
	out := make([]DeadMessage, 0, len(msgs))
	for _, m := range msgs {
		raw, _ := m.Values["p"].(string)
		var payload map[string]any
		_ = json.Unmarshal([]byte(raw), &payload)
		out = append(out, DeadMessage{ID: m.ID, Payload: payload})
	}
	return out, nil
}

// RequeueDead 把死信重新投递回任务流并删除原死信。ids 为空表示全部（最多 limit 条）。
func (s *Store) RequeueDead(ctx context.Context, ids []string, limit int) (int, error) {
	if limit <= 0 {
		limit = 100
	}
	var targets []redis.XMessage
	if len(ids) == 0 {
		msgs, err := s.rdb.XRangeN(ctx, s.key(s.tc.DeadStream), "-", "+", int64(limit)).Result()
		if err != nil {
			return 0, err
		}
		targets = msgs
	} else {
		for _, id := range ids {
			msgs, err := s.rdb.XRangeN(ctx, s.key(s.tc.DeadStream), id, id, 1).Result()
			if err != nil {
				continue
			}
			targets = append(targets, msgs...)
		}
	}
	moved := 0
	for _, m := range targets {
		raw, _ := m.Values["p"].(string)
		if raw == "" {
			continue
		}
		if _, err := s.PushRaw(ctx, raw); err != nil {
			continue
		}
		s.rdb.XDel(ctx, s.key(s.tc.DeadStream), m.ID)
		moved++
	}
	return moved, nil
}

// ResetState 清空任务流/死信/统计/游标/重试计数/追加锁。
func (s *Store) ResetState(ctx context.Context) error {
	keys := []string{
		s.key(s.tc.Stream), s.key(s.tc.DeadStream), s.key(s.tc.StatsKey),
	}
	for _, pattern := range []string{"cur:*", "attempt:*", "addlock:*", "job:*"} {
		iter := s.rdb.Scan(ctx, 0, s.prefix+pattern, 500).Iterator()
		for iter.Next(ctx) {
			keys = append(keys, iter.Val())
		}
		if err := iter.Err(); err != nil {
			return err
		}
	}
	if len(keys) > 0 {
		if err := s.rdb.Del(ctx, keys...).Err(); err != nil {
			return err
		}
	}
	// 消费组挂在 stream key 上，DEL 会一并删除，这里立即重建以保证后续可消费。
	return s.EnsureGroup(ctx)
}

func flatten(res []redis.XStream) []Message {
	out := make([]Message, 0)
	for _, stream := range res {
		for _, m := range stream.Messages {
			if msg, ok := toMessage(m); ok {
				out = append(out, msg)
			}
		}
	}
	return out
}

func toMessage(m redis.XMessage) (Message, bool) {
	raw, _ := m.Values["p"].(string)
	if raw == "" {
		return Message{}, false
	}
	p, err := task.Decode([]byte(raw))
	if err != nil {
		return Message{ID: m.ID, Raw: raw}, true
	}
	return Message{ID: m.ID, Payload: p, Raw: raw}, true
}
