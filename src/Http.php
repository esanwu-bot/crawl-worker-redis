<?php
declare(strict_types=1);

namespace Cw;

use RuntimeException;

/**
 * 轻量 HTTP 客户端：浏览器 UA、超时、指数退避重试、礼貌延迟。
 */
class Http
{
    private array $cfg;
    private ?ProxyPool $pool = null;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        // 代理池开关（config.http.proxy.enabled）：默认关闭 => 纯直连，与改造前行为完全一致。
        // opts 内含 redis 连接（config.http.proxy.redis）时池状态自动 Redis 化（多进程共享）。
        $p = $cfg['proxy'] ?? [];
        if (!empty($p['enabled']) && !empty($p['list'])) {
            $this->pool = new ProxyPool($p['list'], $p);
        }
    }

    /** 观测当前代理池（未启用代理时返回 null），供日志与测试断言使用。 */
    public function pool(): ?ProxyPool
    {
        return $this->pool;
    }

    /**
     * GET 并解析 JSON，失败抛异常（调用方决定重试/死信语义）。
     */
    public function getJson(string $url, array $query = []): array
    {
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $lastErr = '';
        $maxRetry = (int)($this->cfg['retries'] ?? 2);

        // 代理模式下额外预算：A 类（代理不可用）失败时可连续换几个代理再试。
        // 总尝试次数在进入循环前一次性算好（不能在循环条件里引用会被消耗的预算，
        // 否则换掉一个代理后剩余轮次会跟着缩水）。
        $proxySwapLeft = $this->pool ? (int)($this->cfg['proxy']['retries'] ?? 2) : 0;
        $maxAttempts   = $maxRetry + $proxySwapLeft;

        for ($attempt = 0; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                usleep((int)($attempt * $attempt * 500_000)); // 1s / 4s
            }
            // null = 直连（未启用代理，或池中暂时没有可用出口）
            $proxy = $this->pool?->next();
            try {
                $body = $this->fetch($url, $proxy);
                $json = json_decode($body, true);
                if (!is_array($json)) {
                    throw new RuntimeException('JSON 解析失败: ' . substr($body, 0, 200));
                }
                return $json;
            } catch (ProxyException $e) {
                // A 类：当前代理不可用（已在 fetch 内 markBad），换下一个代理再来
                $lastErr = $e->getMessage();
                if ($proxySwapLeft > 0) {
                    $proxySwapLeft--;
                    continue;
                }
            } catch (RuntimeException $e) {
                // B/C 类：目标站风控 / 业务错误 —— 抛给上游走既有任务重试语义
                $lastErr = $e->getMessage();
            }
        }
        throw new RuntimeException("GET {$url} 失败: {$lastErr}");
    }

    /**
     * 执行一次 curl 请求，返回原始三元组。
     *
     * 独立成可覆写方法：离线测试注入假传输，即可验证"失败归因 / 换代理重试"而不出网。
     *
     * @return array{body:string|false, code:int, err:string}
     */
    protected function request(string $url, ?array $proxy): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => (int)($this->cfg['timeout'] ?? 20),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => $this->cfg['ua'] ?? '',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/javascript, */*; q=0.01',
                'Accept-Language: zh-CN,zh;q=0.9',
                'Referer: ' . ($this->cfg['referer'] ?? ''),
            ],
            CURLOPT_ENCODING       => '', // 自动解压缩
        ]);

        // 代理出口（ProxyPool::next() 分配；null = 直连）
        if ($proxy !== null) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy['host']);
            if (!empty($proxy['auth'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['auth']);
            }
        }

        // HTTPS 证书策略
        $verify = (bool)($this->cfg['ssl_verify'] ?? true);
        $ca     = (string)($this->cfg['ca_bundle'] ?? '');
        if ($verify && $ca !== '' && is_file($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        } elseif ($verify && ($this->cfg['insecure_fallback'] ?? false)) {
            // 系统无 CA 包且未配置 ca_bundle：降级为不校验证书（仅限本地/演示环境）
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['body' => $body, 'code' => $code, 'err' => $err];
    }

    private function fetch(string $url, ?array $proxy = null): string
    {
        $res  = $this->request($url, $proxy);
        $body = $res['body'];
        $code = (int)$res['code'];
        $err  = (string)($res['err'] ?? '');
        $host = $proxy['host'] ?? '';

        // ---- 失败归因：A 代理不可用 / B 目标风控 / C 目标业务错误 ----
        if ($body === false || $code === 0) {
            // A 类：连接层失败（代理不通 / 隧道超时 / curl 错误）
            $this->markBad($host, $err ?: '连接失败');
            throw new ProxyException(sprintf('代理不可用 %s%s', $host, $err ? " / {$err}" : ''));
        }
        if ($code === 407) {
            // A 类：407 = 代理认证失败（直连时不会出现）
            $this->markBad($host, 'proxy auth 407');
            throw new ProxyException("代理认证失败(407): {$host}");
        }
        if ($code < 200 || $code >= 400) {
            // B 类（403 多为风控）：标记当前出口可疑，交任务层换 IP 重试
            if ($code === 403) {
                $this->markBad($host, '403 疑似风控');
            }
            throw new RuntimeException(sprintf('HTTP %d%s', $code, $err ? " / {$err}" : ''));
        }

        // B 类：站点公共接口偶发返回 HTML（反爬/风控页），做一次形状校验
        if (isset($body[0]) && $body[0] === '<') {
            $this->markBad($host, '返回 HTML 风控页');
            throw new RuntimeException('返回内容为 HTML（疑似被风控拦截）');
        }

        // 成功：恢复该出口的可用状态
        $this->markOk($host);

        if (isset($this->cfg['delay_ms']) && $this->cfg['delay_ms'] > 0) {
            usleep((int)$this->cfg['delay_ms'] * 1000);
        }
        return $body;
    }

    /** 只有本次请求确实走了代理时才有意义；直连时为空操作 */
    private function markBad(string $host, string $reason): void
    {
        if ($host !== '' && $this->pool !== null) {
            $this->pool->markBad($host, $reason);
        }
    }

    private function markOk(string $host): void
    {
        if ($host !== '' && $this->pool !== null) {
            $this->pool->markOk($host);
        }
    }
}
