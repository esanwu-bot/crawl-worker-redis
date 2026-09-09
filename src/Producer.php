<?php
declare(strict_types=1);

namespace Cw;

use Cw\Adapter\AdapterRegistry;
use Cw\Contract\Task;

/**
 * 种子发布器（数据源无关）：
 *  1) 从注册表取 source 对应 Adapter，discover 采集单元（目录沉淀在 source_types 通用表）
 *  2) 建通用作业 crawl_jobs（source 字段只差一个值，跨源共用同一套 Job-Task 模型）
 *  3) 初始化 Redis 游标 Hash 并向 Stream 投递每个单元的首页任务
 *
 * 对“采什么”只了解 Task 契约；协议/字段/分页语义全部在 Adapter 内部。
 */
class Producer
{
    public function __construct(
        private RedisStore      $store,
        private AdapterRegistry $adapters,
        private Db              $db,
        private Logger          $log,
        private array           $seedCfg
    ) {
    }

    /**
     * 对某个数据源执行一轮播种。
     *
     * @param string       $source      数据源标识（registry 已注册的 key）
     * @param array        $wantUnits   指定采集单元 id；空数组则自动取前 limit 个
     * @param bool         $force       true 时重置已完成/采集中游标并重新投递
     * @param int|null     $targetPages 每个单元的目标页上限；null 时读 seedCfg.max_pages
     * @return array{job_id:string,source:string,units:int,seeded:int,skipped:int}
     */
    public function run(string $source, array $wantUnits = [], bool $force = false, ?int $targetPages = null): array
    {
        $adapter = $this->adapters->get($source);
        $maxPages = ($targetPages !== null && $targetPages > 0)
            ? (int)$targetPages
            : (int)($this->seedCfg['max_pages'] ?? 3);

        $this->log->info(sprintf('开始发现数据源 [%s] 的采集单元 ...', $source));
        $units = $adapter->discoverUnits($this->db);
        if (!$units) {
            $this->log->error(sprintf('数据源 [%s] discover 返回空目录，站点结构可能变化', $source));
            return ['job_id' => '', 'source' => $source, 'units' => 0, 'seeded' => 0, 'skipped' => 0];
        }

        $targets = $this->select($source, $units, $wantUnits);
        if (!$targets) {
            $this->log->warn(sprintf('数据源 [%s] 没有匹配的采集单元，未投递任何任务', $source));
            return ['job_id' => '', 'source' => $source, 'units' => count($units), 'seeded' => 0, 'skipped' => 0];
        }

        // 建作业：scope 快照 = 本轮实际播种的单元（Job-Task 模型跨源统一）
        $scope = array_map(fn($u) => [
            'entity'    => (string)$u['entity'],
            'unit_id'   => (string)$u['unit_id'],
            'unit_name' => (string)$u['unit_name'],
        ], $targets);
        $jobId = $this->db->createCrawlJob($source, $scope);

        $this->log->info(sprintf('本轮播种 [%s] 共 %d 个采集单元（job=%s, max_pages=%d）',
            $source, count($targets), $jobId, $maxPages));

        $seeded = $skipped = 0;
        foreach ($targets as $unit) {
            $task      = Task::home($unit, $source, 1, $maxPages, $jobId);
            $cursorKey = Task::cursorKey($task);
            $desc      = Task::describe($task);

            $cursor = $this->store->getCursor($cursorKey);
            $status = (string)($cursor['status'] ?? '');

            if ($force || !$cursor) {
                if ($cursor) {
                    $this->log->info("  {$desc} 重置游标后重新播种");
                }
                $this->store->clearTaskLocks($cursorKey);
                $this->store->initCursor($cursorKey, self::cursorMeta($task));
                $msgId = $this->store->pushTask($task);
                $this->log->info("  {$desc} 已投递首页任务 {$msgId}（max_pages={$maxPages}）");
                $this->store->statIncr('seeded_units');
                $seeded++;
            } elseif ($status === 'done') {
                $this->log->info("  {$desc} 已完成，跳过（加 --force 可重采）");
                $skipped++;
            } else {
                $this->log->info("  {$desc} 采集中/待处理（status={$status}），跳过重复投递");
                $skipped++;
            }
        }

        $this->log->info(sprintf('播种结束（job=%s, seeded=%d, skipped=%d）。可用 `php bin/worker.php` 启动消费。',
            $jobId, $seeded, $skipped));
        return ['job_id' => $jobId, 'source' => $source, 'units' => count($targets),
                'seeded' => $seeded, 'skipped' => $skipped];
    }

    /** 游标 Hash 元数据（来源任务，便于运维观察与 Worker 自愈补建） */
    private static function cursorMeta(array $task): array
    {
        return [
            'source'    => (string)$task['source'],
            'entity'    => (string)$task['entity'],
            'unit_id'   => (string)$task['cursor']['unit_id'],
            'unit_name' => (string)$task['cursor']['unit_name'],
        ];
    }

    /** 选择目标单元：未指定时按 unit_id 升序取 limit 个，避免误采全站 */
    private function select(string $source, array $units, array $wantUnits): array
    {
        if ($wantUnits) {
            $set = array_flip(array_map('strval', $wantUnits));
            $hit = array_values(array_filter($units, fn($u) => isset($set[(string)$u['unit_id']])));
            if (count($hit) !== count($set)) {
                $this->log->warn('部分指定单元 id 未在数据源目录中发现（可能已下架或类型变化）');
            }
            return $hit;
        }
        usort($units, fn($a, $b) => strcmp((string)$a['unit_id'], (string)$b['unit_id']));
        return array_slice($units, 0, (int)($this->seedCfg['limit'] ?? 3));
    }
}
