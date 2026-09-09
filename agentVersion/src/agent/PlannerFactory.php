<?php
declare(strict_types=1);

namespace Cw\Agent;

use Cw\Db;
use Cw\Logger;
use Cw\Agent\Neuron\NeuronPlanner;

/**
 * Planner 工厂：按配置与依赖选择规划器。
 *
 * 选择顺序：
 *  1) agent.llm.base_url 非空 + curl 可用 + Neuron v3 Harness 已装
 *     （class_exists(NeuronAI\Agent\Agent)）-> NeuronPlanner（Agent 化，失败自动回退）
 *  2) agent.llm.base_url 非空 + curl 可用                       -> LlmPlanner（curl 直连）
 *  3) 否则                                                    -> RulePlanner（确定性兜底）
 *
 * NeuronPlanner 内部任一步骤异常都会自行回退到 LlmPlanner，
 * LlmPlanner 再回退 RulePlanner，因此 1) 是安全的渐进升级。
 */
class PlannerFactory
{
    public static function create(Db $db, Logger $log, array $cfg): PlannerInterface
    {
        $llm    = $cfg['llm'] ?? [];
        $policy = $cfg['policy'] ?? [];
        $hasLlm = (string)($llm['base_url'] ?? '') !== '' && function_exists('curl_init');

        if (!$hasLlm) {
            $log->info('[PlannerFactory] 未配置 LLM 或缺少 curl，使用 RulePlanner（确定性）');
            return new RulePlanner($db, $log, $policy);
        }

        $rulePlanner = new RulePlanner($db, $log, $policy);

        if (class_exists(\NeuronAI\Agent\Agent::class)) {
            $log->info('[PlannerFactory] 使用 NeuronPlanner（Neuron v3 Agent Harness）: '
                . ($llm['base_url'] ?? ''));
            return new NeuronPlanner(
                $db,
                $log,
                $llm,
                $policy,
                new LlmPlanner($db, $log, $llm, $policy, $rulePlanner)
            );
        }

        $log->info('[PlannerFactory] Neuron v3 未安装，使用 LlmPlanner: ' . ($llm['base_url'] ?? ''));
        return new LlmPlanner($db, $log, $llm, $policy, $rulePlanner);
    }
}
