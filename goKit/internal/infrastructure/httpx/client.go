// Package httpx 是抓取层统一 HTTP 客户端：
// 超时 / 重试 / UA / Referer / 限速 / 代理轮换 / HTTPS 证书策略集中于此，
// Adapter 内不允许直接使用裸 http.Get。
package httpx

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"time"

	"crawlkit/internal/config"
	"crawlkit/internal/infrastructure/proxy"
)

// ErrProxy 表示“代理不可用”（A 类失败），由调用方换代理重试而非进死信。
var ErrProxy = errors.New("proxy unavailable")

// Client 抓取 HTTP 客户端。
type Client struct {
	cfg  config.HTTPConfig
	hc   *http.Client
	pool *proxy.Pool
}

// New 构建客户端；rdb 用于代理池 Redis 化（可为 nil），prefix 为 Redis key 前缀。
func New(cfg config.HTTPConfig, pool *proxy.Pool) *Client {
	tr := &http.Transport{
		MaxIdleConns:        100,
		MaxIdleConnsPerHost: 8,
		IdleConnTimeout:     60 * time.Second,
		ForceAttemptHTTP2:   true,
	}
	// HTTPS 证书策略：显式 CA；系统缺 CA 时按 insecure_fallback 降级（仅限本地/演示）。
	if cfg.CABundle != "" {
		// 交由系统 roots + 自定义文件。这里用简易方式：关闭校验仅在 fallback 场景。
	}
	if !cfg.SSLVerify && cfg.InsecureFallback {
		tr.TLSClientConfig = &tls.Config{InsecureSkipVerify: true} //nolint:gosec // 显式降级开关
	}
	timeout := time.Duration(cfg.TimeoutSec) * time.Second
	if timeout <= 0 {
		timeout = 20 * time.Second
	}
	return &Client{
		cfg: cfg,
		hc: &http.Client{
			Timeout:   timeout,
			Transport: tr,
			CheckRedirect: func(req *http.Request, via []*http.Request) error {
				if len(via) >= 3 {
					return errors.New("too many redirects")
				}
				return nil
			},
		},
		pool: pool,
	}
}

// Pool 暴露代理池（未启用时为 nil）。
func (c *Client) Pool() *proxy.Pool { return c.pool }

// GetJSON 执行 GET 并解析 JSON，失败返回错误由调用方决定重试/死信语义。
func (c *Client) GetJSON(ctx context.Context, rawURL string, query map[string]string) (map[string]any, error) {
	if len(query) > 0 {
		u, err := url.Parse(rawURL)
		if err != nil {
			return nil, fmt.Errorf("非法 URL %s: %w", rawURL, err)
		}
		q := u.Query()
		for k, v := range query {
			q.Set(k, v)
		}
		u.RawQuery = q.Encode()
		rawURL = u.String()
	}

	maxRetry := c.cfg.Retries
	proxySwapLeft := 0
	if c.pool != nil {
		proxySwapLeft = c.cfg.Proxy.Retries
	}
	maxAttempts := maxRetry + proxySwapLeft

	var lastErr error
	for attempt := 0; attempt <= maxAttempts; attempt++ {
		if attempt > 0 {
			select {
			case <-ctx.Done():
				return nil, ctx.Err()
			case <-time.After(time.Duration(attempt*attempt) * 500 * time.Millisecond):
			}
		}
		var p *proxy.Proxy
		if c.pool != nil {
			p = c.pool.Next(ctx)
		}
		body, err := c.fetch(ctx, rawURL, p)
		if err != nil {
			lastErr = err
			if errors.Is(err, ErrProxy) {
				if proxySwapLeft > 0 {
					proxySwapLeft--
					continue
				}
			}
			continue
		}
		var out map[string]any
		if err := json.Unmarshal(body, &out); err != nil {
			lastErr = fmt.Errorf("JSON 解析失败: %w", err)
			continue
		}
		return out, nil
	}
	if lastErr == nil {
		lastErr = errors.New("unknown error")
	}
	return nil, fmt.Errorf("GET %s 失败: %w", rawURL, lastErr)
}

func (c *Client) fetch(ctx context.Context, rawURL string, p *proxy.Proxy) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, rawURL, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", c.cfg.UA)
	req.Header.Set("Accept", "application/json, text/javascript, */*; q=0.01")
	req.Header.Set("Accept-Language", "zh-CN,zh;q=0.9")
	if c.cfg.Referer != "" {
		req.Header.Set("Referer", c.cfg.Referer)
	}

	client := c.hc
	if p != nil {
		// 使用带代理的独立 client，共享 Transport 配置。
		client = &http.Client{
			Timeout: c.hc.Timeout,
			Transport: &http.Transport{
				Proxy:             http.ProxyURL(proxyUserInfo(p)),
				TLSClientConfig:   c.hc.Transport.(*http.Transport).TLSClientConfig,
				ForceAttemptHTTP2: true,
			},
		}
	}

	resp, err := client.Do(req)
	if err != nil {
		if p != nil {
			c.pool.MarkBad(ctx, p.Host, err.Error())
			return nil, fmt.Errorf("%w: %s / %v", ErrProxy, p.Host, err)
		}
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusProxyAuthRequired {
		if p != nil {
			c.pool.MarkBad(ctx, p.Host, "proxy auth 407")
		}
		return nil, fmt.Errorf("%w: 代理认证失败(407) %s", ErrProxy, p.Host)
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 400 {
		if resp.StatusCode == http.StatusForbidden && p != nil {
			c.pool.MarkBad(ctx, p.Host, "403 疑似风控")
		}
		return nil, fmt.Errorf("HTTP %d", resp.StatusCode)
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, err
	}
	if len(body) > 0 && body[0] == '<' {
		if p != nil {
			c.pool.MarkBad(ctx, p.Host, "返回 HTML 风控页")
		}
		return nil, errors.New("返回内容为 HTML（疑似被风控拦截）")
	}
	if p != nil {
		c.pool.MarkOk(ctx, p.Host)
	}
	if c.cfg.DelayMS > 0 {
		select {
		case <-ctx.Done():
		case <-time.After(time.Duration(c.cfg.DelayMS) * time.Millisecond):
		}
	}
	return body, nil
}

func proxyUserInfo(p *proxy.Proxy) *url.URL {
	u := &url.URL{Scheme: "http", Host: p.Host}
	if p.Auth != "" {
		if i := indexByte(p.Auth, ':'); i >= 0 {
			u.User = url.UserPassword(p.Auth[:i], p.Auth[i+1:])
		} else {
			u.User = url.User(p.Auth)
		}
	}
	return u
}

func indexByte(s string, b byte) int {
	for i := 0; i < len(s); i++ {
		if s[i] == b {
			return i
		}
	}
	return -1
}
