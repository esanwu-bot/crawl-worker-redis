<?php
declare(strict_types=1);

namespace Cw;

use Redis;
use RuntimeException;

/**
 * Redis 任务层：
 *  - Stream：任务队列（消费组 -> PEL -> XAUTOCLAIM 接管超时消息）
 *  - Hash：每个系列一个“游标”（断点续采 + 进度状态）
 *  - Hash：全局统计 + 每条消息的重试次数
 *
 * 所有 key 通过 OPT_PREFIX 自动带上配置前缀，代码内无需拼接。
 */
class RedisStore
{
    private Redis $r;
    private array $conn;
    private array $task;
    private string $prefix;
    private string $stream;
    private string $group;
    private string $dead;

    /** @param array $conn 连接参数（host/port/auth/timeout/prefix/claim_idle）；@param array $task 任务参数（stream/group/...） */
    public function __construct(array $conn, array $task, string $prefix)
    {
        $this->conn   = $conn;
        $this->task   = $task;
        $this->prefix = $prefix;
        $this->stream = $task['stream'];
        $this->group  = $task['group'];
        $this->dead   = $task['dead_stream'];

        $this->r = new Redis();
        $this->r->connect($conn['host'], $conn['port'], $conn['timeout']);
        if (!empty($conn['auth'])) {
            $this->r->auth($conn['auth']);
        }
        $this->r->setOption(Redis::OPT_PREFIX, $prefix);
        $this->ensureGroup();
    }

    /** 幂等创建消费组（流不存在则自动 MKSTREAM 创建） */
    private function ensureGroup(): void
    {
        try {
            $this->r->xGroup('CREATE', $this->stream, $this->group, '0', true);
        } catch (\Throwable $e) {
            // BUSYGROUP 表示组已存在，可忽略
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw new RuntimeException('创建消费组失败: ' . $e->getMessage());
            }
        }
    }

    // ---------------- 任务发布 ----------------

    public function addTask(int $typeId, int $page, string $typeName = '', ?int $targetPages = null, ?string $jobId = null): string
    {
        $fields = [
            'type'    => 'page',
            'type_id' => (string)$typeId,
            'page'    => (string)$page,
        ];
        if ($typeName !== '') {
            $fields['type_name'] = $typeName;
        }
        if ($targetPages !== null && $targetPages > 0) {
            $fields['target_pages'] = (string)$targetPages;
        }
        if ($jobId !== null && $jobId !== '') {
            $fields['job_id'] = $jobId;
        }
        return $this->xAddSafe($this->stream, $fields);
    }

    /** 按原始 payload 追加任务（死信重投等场景使用） */
    public function addRawTask(array $payload): string
    {
        return $this->xAddSafe($this->stream, $payload);
    }

    public function addDead(array $payload, string $reason): void
    {
        $payload['_dead_reason'] = $reason;
        $payload['_dead_at']     = date('Y-m-d H:i:s');
        $this->xAddSafe($this->dead, $payload);
    }

    /** 消息体统一 JSON 打包到 p 字段；MAXLEN ~ 5000 防止演示期流无限增长 */
    private function xAddSafe(string $stream, array $fields): string
    {
        $id = $this->r->xAdd(
            $stream,
            '*',
            ['p' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            5000,
            true
        );
        if ($id === false || $id === '') {
            throw new RuntimeException("XADD {$stream} 失败");
        }
        return $id;
    }

    // ---------------- 消费 ----------------

    /** 读新消息（'>'）。返回 [msgId, payload][] */
    public function readBatch(string $consumer, int $count, int $blockSec): array
    {
        $res = $this->r->xReadGroup($this->group, $consumer, [$this->stream => '>'], $count, $blockSec * 1000);
        return self::flatten($res);
    }

    /**
     * 接管超过 claim_idle 的失联消息（崩溃恢复/失败重试）。
     *
     * 部分云 Redis / 低版本 phpredis 不提供 XAUTOCLAIM，
     * 这里用「XPENDING IDLE 定位 + XCLAIM 转移」等价实现，
     * 两个原生命令均为 Redis 5.0 基础能力，兼容性最好。
     */
    public function claimBatch(string $consumer, int $count): array
    {
        $idle  = (int)$this->conn['claim_idle'];
        $full  = $this->prefix . $this->stream; // rawCommand 不经过 OPT_PREFIX，需手工前缀

        $list = $this->r->rawCommand('XPENDING', $full, $this->group, 'IDLE', $idle, '-', '+', $count);
        $ids  = [];
        if (is_array($list)) {
            foreach ($list as $entry) {
                if (is_array($entry) && isset($entry[0])) {
                    $ids[] = (string)$entry[0];
                }
            }
        }
        if (!$ids) {
            return [];
        }

        $args = array_merge(['XCLAIM', $full, $this->group, $consumer, $idle], $ids);
        $raw  = call_user_func_array([$this->r, 'rawCommand'], $args);

        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (!is_array($entry) || count($entry) < 2) {
                    continue;
                }
                $fields = [];
                $flat = $entry[1];
                for ($i = 0, $n = count($flat); $i + 1 < $n; $i += 2) {
                    $fields[(string)$flat[$i]] = (string)$flat[$i + 1];
                }
                $payload = json_decode((string)($fields['p'] ?? '{}'), true);
                $out[] = [
                    'id'      => (string)$entry[0],
                    'payload' => is_array($payload) ? $payload : [],
                ];
            }
        }
        return $out;
    }

    public function ack(array $ids): void
    {
        if ($ids) {
            $this->r->xack($this->stream, $this->group, $ids);
        }
    }

    /** 把 xReadGroup / xAutoClaim 的嵌套结构拍平 */
    private static function flatten($res): array
    {
        $out = [];
        if (!is_array($res)) {
            return $out;
        }
        foreach ($res as $streamKey => $messages) {
            if (!is_array($messages)) {
                continue;
            }
            foreach ($messages as $msgId => $fields) {
                if (!is_array($fields)) {
                    continue;
                }
                $payload = json_decode((string)($fields['p'] ?? '{}'), true);
                $out[] = [
                    'id'      => (string)$msgId,
                    'payload' => is_array($payload) ? $payload : [],
                ];
            }
        }
        return $out;
    }

    // ---------------- 游标（断点续采） ----------------

    public function cursorExists(int $typeId): bool
    {
        return (bool)$this->r->exists($this->cursorKey($typeId));
    }

    public function initCursor(int $typeId, string $typeName): void
    {
        $this->r->hMSet($this->cursorKey($typeId), [
            'type_id'      => (string)$typeId,
            'type_name'    => $typeName,
            'status'       => 'pending',
            'done_pages'   => '0',
            'total_pages'  => '0',
            'rows'         => '0',
            'attempts'     => '0',
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    public function getCursor(int $typeId): array
    {
        return $this->r->hGetAll($this->cursorKey($typeId));
    }

    public function patchCursor(int $typeId, array $fields): void
    {
        if (!$fields) {
            return;
        }
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $this->r->hMSet($this->cursorKey($typeId), $fields);
    }

    private function cursorKey(int $typeId): string
    {
        return 'cur:' . $typeId;
    }

    /** 幂等“追加下一页”闸门：防止并发/重复投递造成同页任务堆积 */
    public function tryLockNextPage(int $typeId, int $page): bool
    {
        $lock = 'addlock:' . $typeId . ':' . $page;
        return (bool)$this->r->set($lock, '1', ['NX', 'EX' => 60]);
    }

    /** 清理某系列的历史追加锁（--force 重采时避免旧锁阻断翻页链） */
    public function clearTaskLocks(int $typeId): void
    {
        for ($pg = 1; $pg <= 200; $pg++) {
            $this->r->rawCommand('DEL', $this->prefix . 'addlock:' . $typeId . ':' . $pg);
        }
    }

    // ---------------- 运维：整体重置（演示重跑用） ----------------

    public function resetState(): void
    {
        $collect = [];
        foreach ([$this->stream, $this->dead, $this->task['stats_key']] as $k) {
            $collect[] = $this->prefix . $k;
        }
        $this->scanRaw('cur:*', $collect);
        $this->scanRaw('attempt:*', $collect);
        $this->scanRaw('addlock:*', $collect);
        foreach ($collect as $k) {
            $this->r->rawCommand('DEL', $k);
        }
        // 消费组挂在 stream key 上，DEL 后下次 ensureGroup 自动重建
    }

    /** 不带前缀语义的 SCAN（用于清理） */
    private function scanRaw(string $pattern, array &$collect): void
    {
        $cursor = '0';
        do {
            $res = $this->r->rawCommand('SCAN', $cursor, 'MATCH', $this->prefix . $pattern, 'COUNT', 500);
            $cursor = (string)$res[0];
            foreach ($res[1] as $k) {
                $collect[] = $k;
            }
        } while ($cursor !== '0');
    }

    // ---------------- 统计 & 重试次数 ----------------

    public function statIncr(string $field, int $by = 1): void
    {
        $this->r->hIncrBy($this->task['stats_key'], $field, $by);
    }

    public function stats(): array
    {
        $s = $this->r->hGetAll($this->task['stats_key']);
        return $s ?: [];
    }

    public function attemptKey(string $msgId): string
    {
        return 'attempt:' . $msgId;
    }

    public function bumpAttempt(string $msgId): int
    {
        $n = $this->r->incr($this->attemptKey($msgId));
        $this->r->expire($this->attemptKey($msgId), 86400);
        return (int)$n;
    }

    public function delAttempt(string $msgId): void
    {
        $this->r->del($this->attemptKey($msgId));
    }

    public function streamLen(): int
    {
        return (int)$this->r->xLen($this->stream);
    }

    public function deadLen(): int
    {
        return (int)$this->r->xLen($this->dead);
    }

    /** PEL 中待确认（in-flight/失联）消息数，XPENDING 汇总第 1 个元素 */
    public function pendingTotal(): int
    {
        try {
            $info = $this->r->xPending($this->stream, $this->group);
            return (int)($info[0] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ---------------- 死信运维 ----------------

    /** 读取最近 N 条死信（ newest first ） */
    public function readDead(int $count = 20): array
    {
        $res = $this->r->xRevRange($this->dead, '+', '-', $count);
        $out = [];
        if (!is_array($res)) {
            return $out;
        }
        foreach ($res as $msgId => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $payload = json_decode((string)($fields['p'] ?? '{}'), true);
            $out[] = [
                'id'      => (string)$msgId,
                'payload' => is_array($payload) ? $payload : [],
            ];
        }
        return $out;
    }

    /** 把指定死信 id 重新投回任务流 */
    public function requeueDead(array $ids): int
    {
        if (!$ids) {
            return 0;
        }
        $ids = array_map('strval', $ids);
        $all = $this->r->xRange($this->dead, '-', '+');
        if (!is_array($all)) {
            return 0;
        }
        $n = 0;
        foreach ($all as $msgId => $fields) {
            $msgId = (string)$msgId;
            if (!in_array($msgId, $ids, true)) {
                continue;
            }
            if (!is_array($fields)) {
                continue;
            }
            $payload = json_decode((string)($fields['p'] ?? '{}'), true);
            if (!is_array($payload)) {
                continue;
            }
            unset($payload['_dead_reason'], $payload['_dead_at']);
            if (empty($payload['type'])) {
                $payload['type'] = 'page';
            }
            $this->addRawTask($payload);
            $this->r->xDel($this->dead, [$msgId]);
            $n++;
        }
        return $n;
    }

    // ---------------- Agent 级任务控制 ----------------

    public function setJobControl(string $jobId, string $status): void
    {
        $this->r->set('control:' . $jobId, $status, ['EX' => 86400]);
    }

    public function getJobControl(string $jobId): string
    {
        return (string)$this->r->get('control:' . $jobId);
    }

    // ---------------- Worker 心跳（Workbench 仪表盘用）----------------

    /**
     * 写一条 Worker 心跳；TTL 30s，由 Worker 循环每轮刷新。
     * 字段：consumer / last_seen / host / source / round。
     */
    public function heartbeat(string $consumer, array $extra = []): void
    {
        $payload = array_merge([
            'consumer'  => $consumer,
            'last_seen' => date('Y-m-d H:i:s'),
            'host'      => gethostname() ?: 'unknown',
        ], $extra);
        $this->r->hMSet('hb:worker:' . $consumer, $payload);
        $this->r->expire('hb:worker:' . $consumer, 30);
    }

    /** 清掉某 Worker 心跳（优雅退出时主动 DEL，避免依赖 TTL） */
    public function clearHeartbeat(string $consumer): void
    {
        $this->r->del('hb:worker:' . $consumer);
    }

    /** 列出当前所有存活 Worker；age_seconds 由 last_seen 计算 */
    public function workersOverview(): array
    {
        $now = time();
        $out = [];
        $cursor = '0';
        do {
            $res = $this->r->rawCommand('SCAN', $cursor, 'MATCH', $this->prefix . 'hb:worker:*', 'COUNT', 200);
            $cursor = (string)$res[0];
            if (!is_array($res[1] ?? null)) {
                continue;
            }
            foreach ($res[1] as $fullKey) {
                $fullKey = (string)$fullKey;
                // SCAN 返回的 key 已带 prefix，需剥掉再交给 phpredis（OPT_PREFIX 会自动补），
                // 否则 hGetAll 会拼成 prefix+prefix+... 找不到。
                $suffix = substr($fullKey, strlen($this->prefix));
                $name   = substr($suffix, strlen('hb:worker:'));
                $h      = $this->r->hGetAll($suffix) ?: [];
                $last   = isset($h['last_seen']) ? strtotime((string)$h['last_seen']) : 0;
                $h['name']        = $name;
                $h['age_seconds'] = $last > 0 ? ($now - $last) : -1;
                $h['alive']       = ($last > 0) && (($now - $last) <= 30);
                $out[] = $h;
            }
        } while ($cursor !== '0');
        usort($out, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
        return $out;
    }

    // ---------------- 单元进度（Workbench 仪表盘用）----------------

    /**
     * 扫描所有 cur:* 游标，整理成 [type_id, type_name, status, done_pages, total_pages, rows, progress%]。
     * 用于"27/27 页面"等实时进度卡片。
     */
    public function cursorsOverview(int $limit = 200): array
    {
        $out    = [];
        $cursor = '0';
        do {
            $res = $this->r->rawCommand('SCAN', $cursor, 'MATCH', $this->prefix . 'cur:*', 'COUNT', 200);
            $cursor = (string)$res[0];
            if (!is_array($res[1] ?? null)) {
                continue;
            }
            foreach ($res[1] as $fullKey) {
                if (count($out) >= $limit) {
                    break 2;
                }
                $fullKey = (string)$fullKey;
                // 同 workersOverview：剥掉 prefix 再交给 phpredis（OPT_PREFIX 补回），
                // 否则 hGetAll 会拼成 prefix+prefix+... 找不到。
                $suffix = substr($fullKey, strlen($this->prefix));
                $typeId = (int)substr($suffix, 4); // 跳过 "cur:"
                $h      = $this->r->hGetAll($suffix);
                if (!$h) {
                    continue;
                }
                $done  = (int)($h['done_pages'] ?? 0);
                $total = (int)($h['total_pages'] ?? 0);
                $rows  = (int)($h['rows'] ?? 0);
                $h['type_id']     = $typeId;
                $h['done_pages']  = $done;
                $h['total_pages'] = $total;
                $h['rows']        = $rows;
                $h['progress']    = $total > 0 ? round($done / $total * 100, 1) : 0.0;
                $out[] = $h;
            }
        } while ($cursor !== '0');
        usort($out, fn($a, $b) => $a['type_id'] <=> $b['type_id']);
        return $out;
    }
}
