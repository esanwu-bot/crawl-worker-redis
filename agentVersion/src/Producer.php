<?php
declare(strict_types=1);

namespace Cw;

/**
 * 种子发布器：
 *  1) 从站点拉取产品系列（productType）
 *  2) 元数据写 MySQL（source_types）
 *  3) 初始化 Redis 游标 Hash
 *  4) 向 Stream 投递每个系列的首页任务
 */
class Producer
{
    public function __construct(
        private RedisStore $store,
        private ApiClient  $api,
        private Db         $db,
        private Logger     $log,
        private array      $seedCfg,
        private string     $source
    ) {
    }

    /**
     * @param int[] $wantTypes 指定系列 id；空数组则自动取前 limit 个
     * @param bool  $force     为 true 时重置游标并重新投递首页任务
     * @param int|null $targetPages 每个系列最多抓多少页；null 时读 seedCfg
     * @param string|null $jobId 所属 Agent Job ID（用于任务级控制）
     */
    public function run(array $wantTypes = [], bool $force = false, ?int $targetPages = null, ?string $jobId = null): void
    {
        $maxPages = (int)($this->seedCfg['max_pages'] ?? 3);
        if ($targetPages === null || $targetPages <= 0) {
            $targetPages = (int)($this->seedCfg['target_pages'] ?? $maxPages);
        }
        $this->log->info('开始发现系列 ...');

        $types = $this->api->productTypes();
        if (!$types) {
            $this->log->error('productType 返回空，站点结构可能变化');
            return;
        }
        $this->log->info(sprintf('站点系列共 %d 个', count($types)));

        $targets = $this->select($types, $wantTypes);
        if (!$targets) {
            $this->log->warn('没有匹配的系列，未投递任何任务');
            return;
        }

        $this->log->info(sprintf('本轮播种 %d 个系列：%s', count($targets),
            implode(',', array_map(fn($t) => $t['id'] . ':' . $t['cn_name'], $targets))));

        foreach ($targets as $t) {
            $typeId = (int)$t['id'];
            $cnName = trim((string)($t['cn_name'] ?? $t['name'] ?? ''));
            $enName = trim((string)($t['en_name'] ?? ''));

            // 元数据先落 MySQL，即使任务还没跑也能看到目录
            $this->db->upsertType([
                'source'  => $this->source,
                'type_id' => $typeId,
                'cn_name' => $cnName,
                'en_name' => $enName,
            ]);

            $cursor = $this->store->getCursor($typeId);
            $status = $cursor['status'] ?? '';

            if ($force || !$cursor) {
                if ($cursor) {
                    $this->log->info("  [{$typeId}] 重置游标后重新播种");
                }
                // 清掉上一轮残留的“追加下一页”锁，否则旧锁会阻断翻页链
                $this->store->clearTaskLocks($typeId);
                $this->store->initCursor($typeId, $cnName);
                $msgId = $this->store->addTask($typeId, 1, $cnName, $targetPages, $jobId);
                $this->log->info("  [{$typeId}] {$cnName} 已投递首页任务 {$msgId}（target_pages={$targetPages}）");
                $this->store->statIncr('seeded_types');
            } elseif ($status === 'done') {
                $this->log->info("  [{$typeId}] {$cnName} 已完成，跳过（加 --force 可重采）");
            } else {
                $this->log->info("  [{$typeId}] {$cnName} 采集中/待处理（status={$status}），跳过重复投递");
            }
        }

        $this->log->info('播种结束。可用 `php bin/worker.php` 启动消费。');
    }

    /** 选择目标系列：未指定时按 id 升序取 limit 个，避免误采全站 */
    private function select(array $types, array $wantTypes): array
    {
        if ($wantTypes) {
            $set = array_flip($wantTypes);
            $hit = array_values(array_filter($types, fn($t) => isset($set[(int)$t['id']])));
            if (count($hit) !== count($set)) {
                $this->log->warn('部分指定 id 未在站点系列中发现（可能已下架或类型变化）');
            }
            return $hit;
        }
        usort($types, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
        return array_slice($types, 0, (int)($this->seedCfg['limit'] ?? 3));
    }
}
