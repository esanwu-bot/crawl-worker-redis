<?php
declare(strict_types=1);

namespace Cw;

/**
 * 常驻消费者（消费组内一个 consumer）：
 *  循环 = XAUTOCLAIM 接管失联消息（失败重试/崩溃恢复）
 *        + XREADGROUP 阻塞读新任务
 *  每个任务：拉列表页 -> 清洗 -> MySQL 幂等 upsert -> 推进游标 -> 追加下一页
 *  语义：失败消息不 ACK（留在 PEL），超过 claim_idle 自动被接管重试；
 *        尝试达上限转入死信流，保证 at-least-once 与可观测性。
 */
class Worker
{
    private Logger $log;
    private string $consumer;
    private int $maxIdleRounds;
    private int $round = 0;

    public function __construct(
        private RedisStore $store,
        private ApiClient  $api,
        private Db         $db,
        private string     $apiBase,
        private array      $seedCfg,
        private array      $taskCfg,
        private string     $source
    ) {
        $this->log = new Logger(dirname(__DIR__) . '/logs', 'worker');
    }

    public function start(string $consumer, int $maxIdleRounds): void
    {
        $this->consumer = $consumer;
        $this->maxIdleRounds = $maxIdleRounds;

        $this->log->info("Worker {$consumer} 启动，消费组=" . $this->taskCfg['group']
            . ', stream=' . $this->taskCfg['stream'] . '，空转 '
            . $maxIdleRounds . ' 轮后自动退出');

        $idle = 0;
        while (true) {
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
        }
        $this->printStats(true);
        $this->log->info("{$consumer} 已退出（总轮次 {$this->round}）");
    }

    // ---------------- 单任务处理 ----------------

    private function process(array $item, bool $claimed): void
    {
        $msgId  = $item['id'];
        $p      = $item['payload'];
        $kind   = $p['type'] ?? '';
        $typeId = (int)($p['type_id'] ?? 0);
        $page   = (int)($p['page'] ?? 0);

        if ($kind !== 'page' || $typeId <= 0 || $page <= 0) {
            $this->store->ack([$msgId]);
            $this->store->addDead($p, '非法任务载荷');
            return;
        }

        // 游标自愈：找不到游标（例如绕过 seed 直接投递）时补建
        $cursor = $this->store->getCursor($typeId);
        if (!$cursor) {
            $this->store->initCursor($typeId, (string)($p['type_name'] ?? 'type-' . $typeId));
            $cursor = $this->store->getCursor($typeId);
        }

        // 断点守卫：该页已被成功处理过（重投/重复消息直接确认）
        $donePages = (int)($cursor['done_pages'] ?? 0);
        if ($page <= $donePages) {
            $this->store->ack([$msgId]);
            return;
        }

        $typeName = (string)($p['type_name'] ?? $cursor['type_name'] ?? '');

        try {
            $maxPages = (int)($this->seedCfg['max_pages'] ?? 3);
            $limit    = (int)($this->taskCfg['page_limit'] ?? 15);

            $resp = $this->api->productList($typeId, $page, $limit);
            $items = $resp['data'] ?? [];
            if (!is_array($items)) {
                $items = [];
            }
            $lastPage  = max(1, (int)($resp['last_page'] ?? 1));
            $sourceUrl = $this->apiBase . '/productList?product_type_id='
                . $typeId . '&page=' . $page . '&limit=' . $limit;

            $rows = Normalizer::toModelRows($items, $this->source, $typeId, $typeName, $sourceUrl);
            $inserted = count($rows);
            $this->db->upsertModels($rows);

            $prevRows   = (int)($cursor['rows'] ?? 0);
            $reachedEnd = $page >= $lastPage || $page >= $maxPages;

            $this->store->patchCursor($typeId, [
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
                if ($this->store->tryLockNextPage($typeId, $page + 1)) {
                    $this->store->addTask($typeId, $page + 1, $typeName);
                }
            }

            $this->log->info(sprintf(
                '[%s] p%d/%d %s(%s) 写入 %d 行，游标 page=%d total=%d rows=%d%s',
                $typeId,
                $page,
                $lastPage,
                $typeName,
                $claimed ? 'CLAIM' : 'NEW',
                $inserted,
                $page,
                $lastPage,
                $prevRows + $inserted,
                $reachedEnd ? ' [END]' : ''
            ));
        } catch (\Throwable $e) {
            $this->onFail($msgId, $p, $typeId, $typeName, $e);
        }
    }

    /** 失败语义：留在 PEL 等 XAUTOCLAIM；超限转死信 */
    private function onFail(string $msgId, array $p, int $typeId, string $typeName, \Throwable $e): void
    {
        $maxAttempts = (int)$this->taskCfg['max_attempts'];
        $attempt = $this->store->bumpAttempt($msgId);
        $this->store->statIncr('page_fail');

        if ($attempt >= $maxAttempts) {
            $this->store->ack([$msgId]);
            $this->store->delAttempt($msgId);
            $this->store->addDead($p, $e->getMessage());
            $this->store->patchCursor($typeId, [
                'status'        => 'dead',
            ]);
            $this->log->error(sprintf(
                '[%s] %s(%s) 重试 %d/%d 仍失败，转入死信: %s',
                $typeId, $typeName ?: '?', $p['page'] ?? '?', $attempt, $maxAttempts, $e->getMessage()
            ));
            return;
        }
        $this->log->warn(sprintf(
            '[%s] %s(%s) 失败(第 %d 次)，留在 PEL，约 %dms 后接管重试: %s',
            $typeId, $typeName ?: '?', $p['page'] ?? '?', $attempt,
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
            '[MySQL] source_types=%d product_models=%d',
            $this->db->countTypes(),
            $this->db->countModels()
        ));
    }
}
