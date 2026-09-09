<?php
declare(strict_types=1);

namespace Cw\Agent;

use Cw\Db;
use PDO;

/**
 * Agent Job 与执行步骤的持久化仓库（MySQL）。
 */
class JobRepository
{
    private PDO $pdo;

    public function __construct(Db $db)
    {
        $this->pdo = $db->pdo();
    }

    public function create(string $id, string $intent): void
    {
        $now = date('Y-m-d H:i:s');
        $st  = $this->pdo->prepare(
            'INSERT INTO agent_jobs (id, intent, status, created_at, updated_at)
             VALUES (:id, :intent, :status, :now, :now)'
        );
        $st->execute([
            ':id'     => $id,
            ':intent' => $intent,
            ':status' => 'pending',
            ':now'    => $now,
        ]);
    }

    public function updateStatus(
        string $id,
        string $status,
        ?array $progress = null,
        ?array $resultSummary = null
    ): void {
        $fields = ['status = :status', 'updated_at = :now'];
        $params = [':id' => $id, ':status' => $status, ':now' => date('Y-m-d H:i:s')];
        if ($progress !== null) {
            $fields[]       = 'progress = :progress';
            $params[':progress'] = json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($resultSummary !== null) {
            $fields[]            = 'result_summary = :result';
            $params[':result'] = json_encode($resultSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $sql = 'UPDATE agent_jobs SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $st  = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    public function get(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM agent_jobs WHERE id = :id');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function list(int $limit = 20, int $offset = 0): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM agent_jobs ORDER BY created_at DESC LIMIT :lim OFFSET :off'
        );
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addRun(
        string $jobId,
        string $step,
        string $status,
        ?array $input = null,
        ?array $output = null
    ): int {
        $now = date('Y-m-d H:i:s');
        $st  = $this->pdo->prepare(
            'INSERT INTO agent_workflow_runs
             (job_id, step, status, input, output, started_at)
             VALUES (:job_id, :step, :status, :input, :output, :now)'
        );
        $st->execute([
            ':job_id' => $jobId,
            ':step'   => $step,
            ':status' => $status,
            ':input'  => $input !== null
                ? json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            ':output' => $output !== null
                ? json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            ':now'    => $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function finishRun(int $runId, string $status, ?array $output = null): void
    {
        $st = $this->pdo->prepare(
            'UPDATE agent_workflow_runs
             SET status = :status, finished_at = :now, output = COALESCE(:output, output)
             WHERE id = :id'
        );
        $st->execute([
            ':id'     => $runId,
            ':status' => $status,
            ':now'    => date('Y-m-d H:i:s'),
            ':output' => $output !== null
                ? json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ]);
    }

    public function runsFor(string $jobId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM agent_workflow_runs WHERE job_id = :job_id ORDER BY id ASC'
        );
        $st->execute([':job_id' => $jobId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
