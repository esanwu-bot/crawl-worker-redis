<?php
declare(strict_types=1);

namespace Cw\Agent;

use Cw\Logger;
use Throwable;

/**
 * Agent 编排工作流：
 *  create → plan → (human approval) → seed → crawl → done/failed
 */
class Workflow
{
    public function __construct(
        private PlannerInterface $planner,
        private EngineCapability $engine,
        private JobRepository $repo,
        private Logger $log,
        private array $policy
    ) {
    }

    /**
     * 执行完整工作流。若计划需要审批且未自动通过，则停在 pending_approval。
     */
    public function execute(string $jobId, string $intent, bool $autoApprove = false): array
    {
        try {
            $this->repo->create($jobId, $intent);
            $this->log->info("[Agent] Job {$jobId} 已创建");

            $runId = $this->repo->addRun($jobId, 'plan', 'running', ['intent' => $intent]);
            $plan  = $this->planner->plan($intent);
            $this->repo->finishRun($runId, 'done', ['plan' => $plan->toArray()]);
            $this->repo->updateStatus($jobId, 'planned', $plan->toArray());
            $this->log->info("[Agent] Job {$jobId} 计划: " . json_encode($plan->toArray()));

            if ($plan->requiresApproval && !$autoApprove) {
                $this->repo->updateStatus($jobId, 'pending_approval', $plan->toArray());
                return [
                    'job_id' => $jobId,
                    'status' => 'pending_approval',
                    'plan'   => $plan->toArray(),
                ];
            }

            return $this->continueFromPlan($jobId, $plan);
        } catch (Throwable $e) {
            $this->repo->updateStatus($jobId, 'failed', ['error' => $e->getMessage()]);
            $this->log->error("[Agent] Job {$jobId} 失败: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 人工审批通过后继续执行。
     */
    public function approve(string $jobId): array
    {
        $job = $this->repo->get($jobId);
        if (!$job) {
            throw new AgentException('Job 不存在: ' . $jobId);
        }
        if ($job['status'] !== 'pending_approval') {
            throw new AgentException('Job 状态不是 pending_approval，无法审批');
        }

        $plan = Plan::fromArray((array)json_decode((string)$job['plan'], true));
        return $this->continueFromPlan($jobId, $plan);
    }

    /**
     * 对运行中的 Job 发送控制信号（pause / resume / cancel）。
     */
    public function control(string $jobId, string $action): array
    {
        $status = match ($action) {
            'pause'   => 'paused',
            'resume'  => '', // 空字符串表示清除暂停，Worker 会恢复消费
            'cancel'  => 'cancelled',
            default   => throw new AgentException('不支持的控制动作: ' . $action),
        };

        $this->engine->setJobControl($jobId, $status);

        $dbStatus = match ($action) {
            'pause'  => 'paused',
            'resume' => 'running',
            'cancel' => 'cancelled',
        };
        $this->repo->updateStatus($jobId, $dbStatus);

        return ['job_id' => $jobId, 'action' => $action, 'control' => $status];
    }

    private function continueFromPlan(string $jobId, Plan $plan): array
    {
        $runId = $this->repo->addRun($jobId, 'seed', 'running', ['plan' => $plan->toArray()]);
        $seedInfo = $this->engine->seed($plan, $jobId);
        $this->repo->finishRun($runId, 'done', $seedInfo);

        $this->repo->updateStatus($jobId, 'running');
        $runId = $this->repo->addRun($jobId, 'crawl', 'running');

        $idleRounds = (int)($this->policy['worker_idle_rounds'] ?? 10);
        $this->engine->runWorkerForJob($jobId, $idleRounds);

        $summary = $this->engine->getStats();
        $summary['job_id'] = $jobId;
        $this->repo->finishRun($runId, 'done', $summary);
        $this->repo->updateStatus($jobId, 'done', null, $summary);

        return [
            'job_id'  => $jobId,
            'status'  => 'done',
            'plan'    => $plan->toArray(),
            'summary' => $summary,
        ];
    }
}
