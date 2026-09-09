<?php
declare(strict_types=1);

namespace Cw\Agent;

use Cw\Db;
use Cw\Logger;

/**
 * Planner 工厂：按配置选择规划器。
 *
 *  - agent.llm.base_url 非空 且 curl 可用 -> LlmPlanner（内部失败自动回退 RulePlanner）
 *  - 否则 -> RulePlanner（确定性关键词匹配，P2 能力，全离线可用）
 */
class PlannerFactory
{
    public static function create(Db $db, Logger $log, array $cfg): PlannerInterface
    {
        $llm = $cfg['llm'] ?? [];
        $policy = $cfg['policy'] ?? [];

        if ((string)($llm['base_url'] ?? '') !== '' && function_exists('curl_init')) {
            $log->info('[PlannerFactory] 使用 LLM Planner: ' . ($llm['base_url'] ?? ''));
            return new LlmPlanner(
                $db,
                $log,
                $llm,
                $policy,
                new RulePlanner($db, $log, $policy)
            );
        }
        $log->info('[PlannerFactory] 未配置 LLM 或缺少 curl，使用 RulePlanner（确定性）');
        return new RulePlanner($db, $log, $policy);
    }
}
