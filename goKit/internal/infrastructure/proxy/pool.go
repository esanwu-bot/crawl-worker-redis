// Package proxy 实现代理 IP 池：出口分配 / 健康标记 / 冷却与弃用。
//
// 状态可落 Redis（多 Worker 共享、重启恢复）；未提供 Redis 客户端时退回进程内存，
// 行为完全一致，便于离线测试。
//
// Redis 数据结构（默认带配置 prefix）：
//
//	proxy:pool     HASH，field=出口 host，value=JSON{auth,ok,fail,cooldown_until}
//	proxy:pool:rr  轮询计数器（INCR，单调自增，跨进程公平轮换）
package proxy

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"sync"
	"time"

	"github.com/redis/go-redis/v9"
)

// Proxy 是一个可用的代理出口。
type Proxy struct {
	Host string `json:"-"`
	Auth string `json:"auth"`
}

type state struct {
	Host          string  `json:"-"`
	Auth          string  `json:"auth"`
	OK            bool    `json:"ok"`
	Fail          int     `json:"fail"`
	CooldownUntil float64 `json:"cooldown_until"`
}

// Config 代理池参数。
type Config struct {
	Mode           string // pool=轮换 / static=固定第一个
	Cooldown       time.Duration
	DropAfterFails int
	HashKey        string // Redis 哈希名，默认 proxy:pool
}

// Pool 代理池。
type Pool struct {
	mu       sync.Mutex
	proxies  map[string]*state
	order    []string
	static   string
	staticOn bool
	cfg      Config

	rdb    redis.UniversalClient
	rr     int
	locked bool
}

// New 创建代理池；list 形如 "user:pass@1.2.3.4:8080" 或 "1.2.3.4:8080"。
// rdb 可为 nil（内存模式）。prefix 为 Redis key 前缀。
func New(list []string, cfg Config, rdb redis.UniversalClient, prefix string) *Pool {
	if cfg.Cooldown <= 0 {
		cfg.Cooldown = 30 * time.Second
	}
	if cfg.DropAfterFails <= 0 {
		cfg.DropAfterFails = 3
	}
	if cfg.HashKey == "" {
		cfg.HashKey = "proxy:pool"
	}
	if prefix != "" {
		cfg.HashKey = prefix + cfg.HashKey
	}
	p := &Pool{
		proxies:  map[string]*state{},
		staticOn: cfg.Mode == "static",
		cfg:      cfg,
		rdb:      rdb,
	}
	p.syncIn(context.Background())
	first := ""
	for host, auth := range parseList(list) {
		if first == "" {
			first = host
		}
		if _, ok := p.proxies[host]; !ok {
			p.proxies[host] = &state{Host: host, Auth: auth, OK: true}
			p.order = append(p.order, host)
		} else if p.proxies[host].Auth != auth {
			p.proxies[host].Auth = auth
		}
	}
	p.static = first
	if p.static == "" && len(p.order) > 0 {
		p.static = p.order[0]
	}
	p.persistAll(context.Background())
	return p
}

// Next 取一个可用代理；全池冷却/弃用时返回 nil（调用方本次请求走直连）。
func (p *Pool) Next(ctx context.Context) *Proxy {
	if p == nil {
		return nil
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	p.syncIn(ctx)
	total := len(p.order)
	if total == 0 {
		return nil
	}
	now := float64(time.Now().UnixNano()) / 1e9

	if p.staticOn {
		if s, ok := p.proxies[p.static]; ok && usable(s, now) {
			return &Proxy{Host: s.Host, Auth: s.Auth}
		}
		return nil
	}
	start := p.takeStart(ctx, total)
	for i := 0; i < total; i++ {
		s := p.proxies[p.order[(start+i)%total]]
		if s != nil && usable(s, now) {
			return &Proxy{Host: s.Host, Auth: s.Auth}
		}
	}
	return nil
}

// MarkBad 记录一次失败：计数 + 冷却，连续失败达阈值则弃用。返回累计失败次数。
func (p *Pool) MarkBad(ctx context.Context, host, reason string) int {
	if p == nil || host == "" {
		return 0
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	p.syncIn(ctx)
	s, ok := p.proxies[host]
	if !ok {
		return 0
	}
	s.Fail++
	if s.Fail >= p.cfg.DropAfterFails {
		s.OK = false
		s.CooldownUntil = 0
	} else {
		now := float64(time.Now().UnixNano()) / 1e9
		s.CooldownUntil = now + p.cfg.Cooldown.Seconds()*float64(s.Fail)
	}
	p.persistAll(ctx)
	return s.Fail
}

// MarkOk 记录一次成功：清零失败计数、解除冷却、恢复可用。
func (p *Pool) MarkOk(ctx context.Context, host string) {
	if p == nil || host == "" {
		return
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	p.syncIn(ctx)
	s, ok := p.proxies[host]
	if !ok {
		return
	}
	s.Fail = 0
	s.OK = true
	s.CooldownUntil = 0
	p.persistAll(ctx)
}

// Stats 返回池内所有出口状态快照。
func (p *Pool) Stats(ctx context.Context) []map[string]any {
	if p == nil {
		return nil
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	p.syncIn(ctx)
	now := float64(time.Now().UnixNano()) / 1e9
	out := make([]map[string]any, 0, len(p.order))
	for _, host := range p.order {
		s := p.proxies[host]
		status := "ok"
		switch {
		case !s.OK || s.Fail >= p.cfg.DropAfterFails:
			status = "dropped"
		case !usable(s, now):
			status = "cooldown"
		case s.Fail > 0:
			status = "cooling"
		}
		out = append(out, map[string]any{"host": s.Host, "fail": s.Fail, "status": status})
	}
	return out
}

// Size 返回池规模。
func (p *Pool) Size() int {
	if p == nil {
		return 0
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	return len(p.order)
}

// Clear 清空池状态。
func (p *Pool) Clear(ctx context.Context) {
	if p == nil {
		return
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.rdb != nil {
		p.rdb.Del(ctx, p.cfg.HashKey, p.cfg.HashKey+":rr")
	}
	p.proxies = map[string]*state{}
	p.order = nil
	p.static = ""
	p.rr = 0
}

func usable(s *state, now float64) bool { return s.OK && s.CooldownUntil <= now }

func (p *Pool) takeStart(ctx context.Context, total int) int {
	if p.rdb != nil {
		n, err := p.rdb.Incr(ctx, p.cfg.HashKey+":rr").Result()
		if err == nil {
			return int((n - 1) % int64(total))
		}
	}
	s := p.rr
	p.rr = (p.rr + 1) % total
	return s
}

func (p *Pool) syncIn(ctx context.Context) {
	if p.rdb == nil {
		return
	}
	all, err := p.rdb.HGetAll(ctx, p.cfg.HashKey).Result()
	if err != nil || len(all) == 0 {
		return
	}
	loaded := map[string]*state{}
	for host, raw := range all {
		var s state
		if json.Unmarshal([]byte(raw), &s) != nil {
			continue
		}
		s.Host = host
		loaded[host] = &s
	}
	// 保留本地顺序（配置注入序），再追加库内新增出口，避免轮换跳动。
	merged := make(map[string]*state, len(p.order))
	order := make([]string, 0, len(p.order)+len(loaded))
	for _, host := range p.order {
		if s, ok := loaded[host]; ok {
			merged[host] = s
			delete(loaded, host)
		} else if s, ok := p.proxies[host]; ok {
			merged[host] = s
		}
		order = append(order, host)
	}
	for host, s := range loaded {
		merged[host] = s
		order = append(order, host)
	}
	p.proxies = merged
	p.order = order
}

func (p *Pool) persistAll(ctx context.Context) {
	if p.rdb == nil {
		return
	}
	fields := make(map[string]any, len(p.order))
	for _, host := range p.order {
		s := p.proxies[host]
		if s == nil {
			continue
		}
		b, _ := json.Marshal(map[string]any{
			"auth": s.Auth, "ok": s.OK, "fail": s.Fail, "cooldown_until": s.CooldownUntil,
		})
		fields[host] = string(b)
	}
	if len(fields) > 0 {
		_ = p.rdb.HSet(ctx, p.cfg.HashKey, fields).Err()
	}
}

func parseList(list []string) map[string]string {
	out := map[string]string{}
	for _, item := range list {
		item = strings.TrimSpace(item)
		if item == "" {
			continue
		}
		host, auth := item, ""
		if i := strings.LastIndex(item, "@"); i >= 0 {
			auth = item[:i]
			host = item[i+1:]
		}
		if host == "" {
			continue
		}
		if _, ok := out[host]; !ok {
			out[host] = auth
		}
	}
	return out
}

// String 便于日志输出。
func (p *Proxy) String() string {
	if p == nil {
		return "direct"
	}
	return fmt.Sprintf("%s", p.Host)
}
