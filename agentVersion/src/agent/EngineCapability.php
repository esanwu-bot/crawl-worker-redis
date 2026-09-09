<?php
declare(strict_types=1);

namespace Cw\Agent;

use Cw\ApiClient;
use Cw\Db;
use Cw\Logger;
use Cw\Producer;
use Cw\RedisStore;
use Cw\Worker;

/**
 * Engine 能力封装：把 Crawler Engine 的底层操作抽象成 Agent 可调用的能力单元。
 * Orchestrator 通过它“使用” Engine，而不直接依赖 Engine 内部类。
 */
class EngineCapability
{
    public function __construct(
        private Producer $producer,
        private RedisStore $store,
        private Db $db,
        private ApiClient $api,
        private Logger $log,
        private array $cfg
    ) {
    }

    /**
     * 根据计划向 Redis Stream 播种任务。
     */
    public function seed(Plan $plan, string $jobId): array
    {
        $this->producer->run($plan->typeIds, false, $plan->targetPages, $jobId);
        return [
            'job_id'       => $jobId,
            'type_ids'     => $plan->typeIds,
            'target_pages' => $plan->targetPages,
        ];
    }

    /**
     * 为指定 Job 运行 Worker 直到任务队列空转。
     * 当前是同步阻塞运行，适合 CLI run / approve 后的执行阶段。
     */
    public function runWorkerForJob(string $jobId, int $maxIdleRounds = 30): void
    {
        $consumer = 'agent-' . substr($jobId, 0, 8);
        $worker   = new Worker(
            $this->store,
            $this->api,
            $this->db,
            (string)($this->cfg['api_base'] ?? ''),
            $this->cfg['seed'],
            $this->cfg['task'],
            (string)($this->cfg['source'] ?? '')
        );
        $worker->start($consumer, $maxIdleRounds);
    }

    /**
     * 当前引擎运行指标快照。
     */
    public function getStats(): array
    {
        return [
            'pending'       => $this->store->pendingTotal(),
            'stream_len'    => $this->store->streamLen(),
            'dead_letters'  => $this->store->deadLen(),
            'source_types'  => $this->db->countTypes(),
            'product_models'=> $this->db->countModels(),
        ];
    }

    public function getDeadLetters(int $limit = 50): array
    {
        return $this->store->readDead($limit);
    }

    public function requeueDead(array $ids): int
    {
        return $this->store->requeueDead($ids);
    }

    public function setJobControl(string $jobId, string $status): void
    {
        $this->store->setJobControl($jobId, $status);
    }

    public function getJobControl(string $jobId): string
    {
        return $this->store->getJobControl($jobId);
    }
}
