// Package redisstore 封装 Redis 任务层：
//   - Stream：任务队列（消费组 -> PEL -> XPENDING+XCLAIM 接管超时消息）
//   - Hash：每个采集单元一个“游标”（断点续采 + 进度状态）
//   - Hash：全局统计 + 每条消息的重试次数
//   - Key：作业控制状态与暂停暂存队列
//
// 所有 key 统一带配置前缀（默认 cw:），与 PHP 版保持一致，因此两版可共用一个 Redis。
package redisstore

import (
	"context"
	"fmt"
	"time"

	"github.com/redis/go-redis/v9"

	"crawlkit/internal/config"
)

// TaskConfig 任务语义参数。
type TaskConfig struct {
	Stream     string
	Group      string
	DeadStream string
	StatsKey   string
	MaxLen     int64
}

// Store Redis 任务层封装。
type Store struct {
	rdb       redis.UniversalClient
	prefix    string
	claimIdle time.Duration
	tc        TaskConfig
	ownsConn  bool
}

// New 连接 Redis 并幂等创建消费组。
func New(ctx context.Context, rc config.RedisConfig, tc TaskConfig) (*Store, error) {
	rdb := redis.NewClient(&redis.Options{
		Addr:        fmt.Sprintf("%s:%d", rc.Host, rc.Port),
		Password:    rc.Auth,
		DialTimeout: time.Duration(rc.TimeoutMS) * time.Millisecond,
		PoolSize:    16,
	})
	if err := rdb.Ping(ctx).Err(); err != nil {
		return nil, fmt.Errorf("Redis 连接失败 %s:%d: %w", rc.Host, rc.Port, err)
	}
	if tc.MaxLen <= 0 {
		tc.MaxLen = 5000
	}
	if tc.Stream == "" {
		tc.Stream = "tasks"
	}
	if tc.Group == "" {
		tc.Group = "workers"
	}
	if tc.DeadStream == "" {
		tc.DeadStream = "tasks:dead"
	}
	if tc.StatsKey == "" {
		tc.StatsKey = "stats"
	}
	s := &Store{
		rdb:       rdb,
		prefix:    rc.Prefix,
		claimIdle: time.Duration(rc.ClaimIdleMS) * time.Millisecond,
		tc:        tc,
		ownsConn:  true,
	}
	if err := s.EnsureGroup(ctx); err != nil {
		return nil, err
	}
	return s, nil
}

// NewWithClient 使用外部 Redis 客户端构造（便于测试或复用连接）。
func NewWithClient(ctx context.Context, rdb redis.UniversalClient, prefix string, claimIdle time.Duration, tc TaskConfig) (*Store, error) {
	s := &Store{rdb: rdb, prefix: prefix, claimIdle: claimIdle, tc: tc}
	if err := s.EnsureGroup(ctx); err != nil {
		return nil, err
	}
	return s, nil
}

// Client 暴露底层客户端。
func (s *Store) Client() redis.UniversalClient { return s.rdb }

// Prefix 返回 key 前缀。
func (s *Store) Prefix() string { return s.prefix }

// Close 关闭自有连接。
func (s *Store) Close() error {
	if s.ownsConn {
		return s.rdb.Close()
	}
	return nil
}

// TaskConfig 返回任务配置。
func (s *Store) TaskConfig() TaskConfig { return s.tc }

func (s *Store) key(name string) string { return s.prefix + name }

// EnsureGroup 幂等创建消费组（流不存在则自动 MKSTREAM 创建）。
func (s *Store) EnsureGroup(ctx context.Context) error {
	err := s.rdb.XGroupCreateMkStream(ctx, s.key(s.tc.Stream), s.tc.Group, "0").Err()
	if err != nil && !isBusyGroup(err) {
		return fmt.Errorf("创建消费组失败: %w", err)
	}
	return nil
}

func isBusyGroup(err error) bool {
	return err != nil && contains(err.Error(), "BUSYGROUP")
}

func contains(s, sub string) bool {
	for i := 0; i+len(sub) <= len(s); i++ {
		if s[i:i+len(sub)] == sub {
			return true
		}
	}
	return false
}
