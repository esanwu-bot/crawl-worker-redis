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

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
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

        for ($attempt = 0; $attempt <= $maxRetry; $attempt++) {
            if ($attempt > 0) {
                usleep((int)($attempt * $attempt * 500_000)); // 1s / 4s
            }
            try {
                $body = $this->fetch($url);
                $json = json_decode($body, true);
                if (!is_array($json)) {
                    throw new RuntimeException('JSON 解析失败: ' . substr($body, 0, 200));
                }
                return $json;
            } catch (RuntimeException $e) {
                $lastErr = $e->getMessage();
            }
        }
        throw new RuntimeException("GET {$url} 失败: {$lastErr}");
    }

    private function fetch(string $url): string
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

        if ($body === false || $code < 200 || $code >= 400) {
            throw new RuntimeException(sprintf('HTTP %d%s', $code, $err ? " / {$err}" : ''));
        }

        // 站点公共接口偶发返回 HTML（反爬/风控页），做一次形状校验
        if (isset($body[0]) && $body[0] === '<') {
            throw new RuntimeException('返回内容为 HTML（疑似被风控拦截）');
        }

        if (isset($this->cfg['delay_ms']) && $this->cfg['delay_ms'] > 0) {
            usleep((int)$this->cfg['delay_ms'] * 1000);
        }
        return $body;
    }
}
