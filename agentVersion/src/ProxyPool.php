<?php
declare(strict_types=1);

namespace Cw;

use Redis;
use RuntimeException;

/**
 * 代理 IP 池（Redis 化，Phase 2）。
 *
 * 职责（与站点抓取无关，纯"出口分配"管理）：
 *  1) 进货        —— list 由 config 注入（可来自静态地址 / 隧道域名 / 供应商提取接口）
 *  2) 分配 next()  —— round-robin 轮换；mode=static 时固定第一项
 *  3) 健康标记    —— markBad(冷却/弃用) / markOk(恢复)
 *  4) 观测 stats() —— 池规模 / 可用数 / 各代理失败次数
 *
 * 存储两种模式（opts['redis'] 存在即 Redis 化，否则回退进程内存）：
 *  - Redis 化：状态落在任务层同一 Redis（同 prefix）的 HASH 上，多 Worker 共享、
 *    重启可恢复；跨进程轮询指针用原子 INCR，避免多进程重复取同一出口。
 *  - 内存：离线单测 / 无 Redis 时的兜底，行为与 Redis 模式完全一致。
 *
 * Redis 数据结构（均自动带配置 prefix，如 cw:shikues:proxy:pool）：
 *  - proxy:pool   HASH，field=出口 host，value=JSON{auth, ok, fail, cooldown_until}
 *  - proxy:pool:rr  轮询计数器（INCR，单调自增）
 *
 * 并发注记：单出口同一时刻被两个进程标记失败属极小概率；markBad 为"读-改-写"，
 * 极端并发下冷却计数可能少计一次，但不会产生错误出口分配，演示场景可接受。
 */
class ProxyPool
{
    /** 出口状态快照：host => {host,auth,ok,fail,cooldown_until}（内存模式即最终存储） */
    private array $proxies = [];

    private bool $staticMode;
    private int $cooldownMs;
    private int $dropAfterFails;
    private ?string $staticHost = null;   // static 模式的固定出口（配置列表第一项）

    // ---- Redis 化（Phase 2）：null = 内存模式 ----
    private ?Redis $r = null;
    private string $hashKey = 'proxy:pool';
    private string $rrKey   = 'proxy:pool:rr';
    private int $rr = 0;                  // 内存轮询指针（Redis 模式用 INCR 代替）

    /**
     * @param string[] $list 形如 "user:pass@1.2.3.4:8080" 或 "1.2.3.4:8080"
     * @param array    $opts mode / cooldown_ms / drop_after_fails / redis(连接) / key(哈希名)
     */
    public function __construct(array $list, array $opts = [])
    {
        $this->staticMode     = ($opts['mode'] ?? 'pool') === 'static';
        $this->cooldownMs     = (int)($opts['cooldown_ms'] ?? 30000);
        $this->dropAfterFails = max(1, (int)($opts['drop_after_fails'] ?? 3));

        $rc = $opts['redis'] ?? null;
        if (is_array($rc) && $rc !== []) {
            $this->connectRedis($rc);
            $this->hashKey = (string)($opts['key'] ?? 'proxy:pool');
            $this->rrKey   = $this->hashKey . ':rr';
        }
        $this->seed($list);
    }

    /** 按配置注入清单并保证池内条目存在；Redis 模式下会先载入库内既有状态（重启恢复）。 */
    private function seed(array $list): void
    {
        $parsed = self::parseList($list);
        if ($this->r !== null) {
            $this->syncIn(); // 先恢复历史状态，配置清单只做"补齐/认证校正"，不重置失败计数
        }
        $first = null;
        foreach ($parsed as $host => $auth) {
            $first ??= $host;
            if (isset($this->proxies[$host])) {
                if ($this->proxies[$host]['auth'] !== $auth) {
                    $this->proxies[$host]['auth'] = $auth;
                }
                continue;
            }
            $this->proxies[$host] = self::fresh($host, $auth);
        }
        if ($this->r !== null) {
            $this->persistAll();
        }
        // static 固定"配置第一个"；无配置时兜底为池内第一个
        $this->staticHost = $first;
        if ($this->staticHost === null) {
            $k = array_key_first($this->proxies);
            $this->staticHost = $k !== null ? $k : null;
        }
    }

    /**
     * 取一个可用代理；全池冷却/弃用时返回 null（调用方本次请求走直连）。
     *
     * @return array{host:string,auth:string}|null
     */
    public function next(): ?array
    {
        $this->syncIn();
        $total = count($this->proxies);
        if ($total === 0) {
            return null;
        }

        // static 模式：始终用固定出口（隧道 / 评审演示场景）
        if ($this->staticMode) {
            $host = $this->staticHost;
            if ($host === null || !isset($this->proxies[$host])) {
                $host = array_key_first($this->proxies);
            }
            if ($host !== null && $this->usable($this->proxies[$host])) {
                $p = $this->proxies[$host];
                return ['host' => $p['host'], 'auth' => $p['auth']];
            }
            return null;
        }

        $keys = array_keys($this->proxies);
        $start = $this->takeStart($total);
        for ($i = 0; $i < $total; $i++) {
            $p = $this->proxies[$keys[($start + $i) % $total]];
            if ($this->usable($p)) {
                return ['host' => $p['host'], 'auth' => $p['auth']];
            }
        }
        return null;
    }

    /** 代理本次请求失败：计数 + 冷却；连续失败达阈值则弃用。返回累计失败次数。 */
    public function markBad(string $host, string $reason = ''): int
    {
        $this->syncIn();
        if (!isset($this->proxies[$host])) {
            return 0;
        }
        $p = &$this->proxies[$host];
        $p['fail']++;
        $fails = $p['fail'];
        if ($fails >= $this->dropAfterFails) {
            $p['ok']             = false;      // 弃用（不再被 next() 分配）
            $p['cooldown_until'] = 0.0;
        } else {
            // 冷却时长随连续失败递增：base、2×base…
            $p['cooldown_until'] = microtime(true) + $this->cooldownMs * $fails / 1000;
        }
        unset($p);
        $this->persistAll();
        return $fails;
    }

    /** 代理请求成功：清零失败计数、解除冷却、恢复可用。 */
    public function markOk(string $host): void
    {
        $this->syncIn();
        if (!isset($this->proxies[$host])) {
            return;
        }
        $this->proxies[$host]['fail']           = 0;
        $this->proxies[$host]['ok']             = true;
        $this->proxies[$host]['cooldown_until'] = 0.0;
        $this->persistAll();
    }

    public function size(): int
    {
        $this->syncIn();
        return count($this->proxies);
    }

    public function countAvailable(): int
    {
        $this->syncIn();
        $n = 0;
        foreach ($this->proxies as $p) {
            if ($this->usable($p)) {
                $n++;
            }
        }
        return $n;
    }

    /** 池内所有代理状态（供日志 / 演示观测）。 */
    public function stats(): array
    {
        $this->syncIn();
        $out = [];
        foreach ($this->proxies as $p) {
            $out[] = [
                'host'   => $p['host'],
                'fail'   => $p['fail'],
                'status' => $this->usable($p)
                    ? ($p['fail'] > 0 ? 'cooling' : 'ok')
                    : ($p['fail'] >= $this->dropAfterFails ? 'dropped' : 'cooldown'),
            ];
        }
        return $out;
    }

    /** 清空池状态（内存模式清空数组；Redis 模式删除对应 key）。 */
    public function clear(): void
    {
        if ($this->r !== null) {
            $this->r->del($this->hashKey);
            $this->r->del($this->rrKey);
        }
        $this->proxies   = [];
        $this->staticHost = null;
        $this->rr         = 0;
    }

    // ---------------- 存储适配：内存与 Redis 共用一套状态机 ----------------

    private function usable(array $p): bool
    {
        return $p['ok'] && $p['cooldown_until'] <= microtime(true);
    }

    /** 轮询起始位置：Redis 用原子 INCR（跨进程公平轮换），内存用本地指针。 */
    private function takeStart(int $total): int
    {
        if ($this->r !== null) {
            return (((int)$this->r->incr($this->rrKey)) - 1) % $total;
        }
        $s = $this->rr;
        $this->rr = ($this->rr + 1) % $total;
        return $s;
    }

    // ---------------- Redis 化细节 ----------------

    private function connectRedis(array $c): void
    {
        $r = new Redis();
        if (!$r->connect(
            (string)($c['host'] ?? '127.0.0.1'),
            (int)($c['port'] ?? 6379),
            (float)($c['timeout'] ?? 5.0)
        )) {
            throw new RuntimeException('ProxyPool 无法连接 Redis: ' . ($c['host'] ?? ''));
        }
        if (!empty($c['auth'])) {
            $r->auth((string)$c['auth']);
        }
        if (!empty($c['prefix'])) {
            $r->setOption(Redis::OPT_PREFIX, (string)$c['prefix']);
        }
        $this->r = $r;
    }

    /** 每次操作前从 Redis 拉取最新状态（保持本地快照 = 远端事实，天然多进程共享）。 */
    private function syncIn(): void
    {
        if ($this->r === null) {
            return;
        }
        $all = $this->r->hGetAll($this->hashKey);
        if (!is_array($all)) {
            return;
        }
        $loaded = [];
        foreach ($all as $host => $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $p = json_decode($raw, true);
            if (!is_array($p) || !isset($p['auth'])) {
                continue;
            }
            $p['host'] = (string)$host;
            $loaded[$host] = $p;
        }
        // 合并：保留本地已有顺序（配置注入序），再追加库内新增出口，避免轮换跳动
        $merged = [];
        foreach ($this->proxies as $host => $p) {
            if (isset($loaded[$host])) {
                $merged[$host] = $loaded[$host];
                unset($loaded[$host]);
            }
        }
        foreach ($loaded as $host => $p) {
            $merged[$host] = $p;
        }
        $this->proxies = $merged;
    }

    /** 全量写回 Redis HASH（池规模小，全量重写最简单且保证一致）。 */
    private function persistAll(): void
    {
        if ($this->r === null) {
            return;
        }
        $fields = [];
        foreach ($this->proxies as $host => $p) {
            $fields[$host] = json_encode([
                'auth'           => $p['auth'],
                'ok'             => $p['ok'],
                'fail'           => $p['fail'],
                'cooldown_until' => $p['cooldown_until'],
            ], JSON_UNESCAPED_SLASHES);
        }
        $this->r->hMSet($this->hashKey, $fields);
    }

    // ---------------- 清单解析 ----------------

    /** @return array<string,string> host => auth（重复 host 以首个为准，static 固定第一个） */
    private static function parseList(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            $host = $item;
            $auth = '';
            if (($at = strrpos($item, '@')) !== false) {
                $auth = substr($item, 0, $at);
                $host = substr($item, $at + 1);
            }
            if ($host === '') {
                continue;
            }
            if (!isset($out[$host])) {
                $out[$host] = $auth;
            }
        }
        return $out;
    }

    private static function fresh(string $host, string $auth): array
    {
        return [
            'host'           => $host,
            'auth'           => $auth,
            'ok'             => true,
            'fail'           => 0,
            'cooldown_until' => 0.0,
        ];
    }
}
