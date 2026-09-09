<?php
declare(strict_types=1);

namespace Cw;

use Cw\Adapter\AdapterRegistry;
use Cw\Contract\Task;

/**
 * 常驻消费者（消费组内一个 consumer，数据源无关）：
 *  循环 = XAUTOCLAIM 接管失联消息（失败重试/崩溃恢复）
 *        + XREADGROUP 阻塞读新任务
 *  每个任务：按载荷 source 路由到对应 Adapter -> 拉页 -> Canonical Record 落 crawl_records
 *            -> 推进游标 -> 追加下一页
 *  语义：失败消息不 ACK（留在 PEL），超过 claim_idle 自动被接管重试；
 *        尝试达上限转入死信流，保证 at-least-once 与可观测性。
 */
class Worker
{
    private Logger $log;
    private string $consumer;
    private int $maxIdleRounds;
    private int $round = 0;
    private bool $stop = false;

    public function __construct(
        private RedisStore      $store,
        private AdapterRegistry $adapters,
        private Db              $db,
        private array           $taskCfg
    ) {
        $this->log = new Logger(dirname(__DIR__) . '/logs', 'worker');
    }

    public function start(string $consumer, int $maxIdleRounds): void
    {
        $this->consumer = $consumer;
        $this->maxIdleRounds = $maxIdleRounds;
        $this->installSignalHandlers();

        $this->log->info("Worker {$consumer} 启动，消费组=" . $this->taskCfg['group']
            . ', stream=' . $this->taskCfg['stream'] . '，空转 '
            . $maxIdleRounds . ' 轮后自动退出');

        $idle = 0;
        while (true) {
            if ($this->stop) {
                $this->log->info("{$consumer} 收到停止信号，优雅退出");
                break;
            }
            if ($maxIdleRounds > 0 && $idle >= $maxIdleRounds) {
                $this->log->info("{$consumer} 已连续空转 {$idle} 轮，退出");
                break;
            }
            $this->round++;
            $handled = 0;

            // 1) 接管 PEL 中超时未确认的消息（其他/自身崩溃留下的失败任务）
            foreach ($this->store->claimBatch($this->consumer, (int)$this->taskCfg['batch']) as $item) {
                $handled++;
                $this->process($item, true);
            }
            // 2) 阻塞读新消息
            foreach ($this->store->readBatch(
                $this->consumer,
                (int)$this->taskCfg['batch'],
                (int)$this->taskCfg['block_sec']
            ) as $item) {
                $handled++;
                $this->process($item, false);
            }

            $idle = ($handled === 0) ? $idle + 1 : 0;

            if ($this->round % 10 === 0) {
                $this->printStats();
            }
            if ($this->stop) {
                $this->log->info("{$consumer} 收到停止信号，优雅退出");
                break;
            }
        }
        $this->printStats(true);
        $this->log->info("{$consumer} 已退出（总轮次 {$this->round}）");
    }

    public function stop(): void
    {
        $this->stop = true;
    }

    /**
     * 常驻模式注册信号：SIGTERM/SIGINT → 优雅退出（手头页处理完、打印最终状态后停）。
     * Windows/未装 pcntl 时自动跳过，此时可直接结束进程（幂等机制兜底）。
     */
    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop());
        pcntl_signal(SIGINT, fn () => $this->stop());
        $this->log->info('已注册信号处理：SIGTERM/SIGINT 优雅退出');
    }

    // ---------------- 单任务处理 ----------------

    private function process(array $item, bool $claimed): void
    {
        $msgId = $item['id'];
        $p     = $item['payload'];

        if (!Task::isValidList($p)) {
            $this->store->ack([$msgId]);
            $this->store->addDead($p, '非法任务载荷（缺少 source/entity/unit/page）');
            return;
        }

        $cursorKey = Task::cursorKey($p);
        $desc      = Task::describe($p);

        try {
            // 数据源路由：Runtime 唯一知晓“数据源”的地方，领域差异全部收敛在 Adapter
            $adapter = $this->adapters->get((string)$p['source']);
        } catch (\Throwable $e) {
            $this->store->ack([$msgId]);
            $this->store->addDead($p, '未注册数据源: ' . $e->getMessage());
            return;
        }

        // 游标自愈：找不到游标（例如绕过 seed 直接投递）时补建
        $cursor = $this->store->getCursor($cursorKey);
        if (!$cursor) {
            $this->store->initCursor($cursorKey, self::cursorMeta($p));
            $cursor = $this->store->getCursor($cursorKey);
        }

        // 断点守卫：该页已被成功处理过（重投/重复消息直接确认）
        $donePages = (int)($cursor['done_pages'] ?? 0);
        $page      = (int)$p['cursor']['page'];
        if ($page <= $donePages) {
            $this->store->ack([$msgId]);
            return;
        }

        try {
            $maxPages   = Task::targetPages($p, (int)($this->taskCfg['max_pages'] ?? 3));
            $limit      = (int)($this->taskCfg['page_limit'] ?? 15);

            $t = $p;
            $t['params'] = array_merge((array)($t['params'] ?? []), ['limit' => $limit]);

            $result  = $adapter->executeList($t);
            $records = is_array($result['records'] ?? null) ? $result['records'] : [];
            $lastPage = max(1, (int)($result['last_page'] ?? $page));

            $jobId = (string)($p['job_id'] ?? '');
            if ($jobId !== '') {
                foreach ($records as &$r) {
                    if (is_array($r)) {
                        $r['job_id'] = $jobId;
                    }
                }
                unset($r);
            }
            $inserted = $this->db->upsertRecords($records);

            $prevRows   = (int)($cursor['rows'] ?? 0);
            $reachedEnd = (bool)$result['ended']
                || $page >= $lastPage
                || $page >= $maxPages;

            $this->store->patchCursor($cursorKey, [
                'status'        => $reachedEnd ? 'done' : 'active',
                'done_pages'    => (string)$page,
                'total_pages'   => (string)$lastPage,
                'rows'          => (string)($prevRows + $inserted),
            ]);

            $this->store->ack([$msgId]);
            $this->store->delAttempt($msgId);
            $this->store->statIncr('page_done');
            $this->store->statIncr('rows_upsert', $inserted);

            if (!$reachedEnd) {
                // 幂等闸门：并发实例下只允许一个投递下一页任务
                $nextTask = Task::next($t);
                if ($this->store->tryLockNextPage($cursorKey, $page + 1)) {
                    $this->store->pushTask($nextTask);
                }
            } else {
                // 单元收尾回写 Job 状态机（跨源统一）
                $this->db->unitFinished($jobId, true, $prevRows + $inserted);
            }

            $this->log->info(sprintf(
                '%s p%d/%d %s 写入 %d 行，游标 page=%d total=%d rows=%d%s',
                $desc, $page, $lastPage,
                $claimed ? 'CLAIM' : 'NEW',
                $inserted, $page, $lastPage,
                $prevRows + $inserted,
                $reachedEnd ? ' [END]' : ''
            ));
        } catch (\Throwable $e) {
            $this->onFail($msgId, $p, $cursorKey, $e);
        }
    }

    /** 失败语义：留在 PEL 等 XAUTOCLAIM；超限转死信并回写 Job 状态机 */
    private function onFail(string $msgId, array $p, string $cursorKey, \Throwable $e): void
    {
        $maxAttempts = (int)$this->taskCfg['max_attempts'];
        $attempt = $this->store->bumpAttempt($msgId);
        $this->store->statIncr('page_fail');
        $desc = Task::describe($p);

        if ($attempt >= $maxAttempts) {
            $this->store->ack([$msgId]);
            $this->store->delAttempt($msgId);
            $this->store->addDead($p, $e->getMessage());
            $this->store->patchCursor($cursorKey, ['status' => 'dead']);

            $jobId = (string)($p['job_id'] ?? '');
            if ($jobId !== '') {
                $this->db->unitFinished($jobId, false, 0);
            }
            $this->log->error(sprintf(
                '%s 重试 %d/%d 仍失败，转入死信: %s',
                $desc, $attempt, $maxAttempts, $e->getMessage()
            ));
            return;
        }
        $this->log->warn(sprintf(
            '%s 失败(第 %d 次)，留在 PEL，约 %dms 后接管重试: %s',
            $desc, $attempt,
            (int)$this->taskCfg['claim_idle'] ?: 0, $e->getMessage()
        ));
    }

    // ---------------- 运行状态 ----------------

    private function printStats(bool $final = false): void
    {
        $stats = $this->store->stats();
        $summary = [
            'stream_len'   => $this->store->streamLen(),
            'pending'      => $this->store->pendingTotal(),
            'dead_letters' => $this->store->deadLen(),
        ];
        $merged = array_merge($summary, $stats);
        ksort($merged);

        $kv = [];
        foreach ($merged as $k => $v) {
            $kv[] = $k . '=' . $v;
        }
        $this->log->info(($final ? '[最终状态] ' : '[运行状态] ') . implode(' ', $kv));
        $this->log->info(sprintf(
            '[MySQL] crawl_jobs=%d crawl_records=%d（legacy product_models=%d）',
            $this->db->countJobs(),
            $this->db->countRecords(),
            $this->db->countModels()
        ));
    }

    private static function cursorMeta(array $p): array
    {
        return [
            'source'    => (string)$p['source'],
            'entity'    => (string)$p['entity'],
            'unit_id'   => (string)$p['cursor']['unit_id'],
            'unit_name' => (string)$p['cursor']['unit_name'],
        ];
    }
}
