<?php
declare(strict_types=1);

namespace Cw\Agent\Neuron;

use Cw\Agent\LlmPlanner;
use Cw\Agent\Plan;
use Cw\Agent\PlannerInterface;
use Cw\Agent\RulePlanner;
use Cw\Db;
use Cw\Logger;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/**
 * Neuron v3 Planner（P3 节点）—— LlmPlanner 的 Harness 化升级。
 *
 * 与 LlmPlanner 共享同一套确定性安全约束（设计文档 §5）：
 *   - 只把真实目录候选注入给 Agent，LLM 只能"选择"；
 *   - Agent 可调用 Engine 只读工具 list_series 交叉核对（read-only，无写副作用）；
 *   - 返回 JSON 经确定性解析 + 目录反查，任何不在目录里的 id 一律丢弃；
 *   - 失败 / 未命中 → 回退 LlmPlanner → RulePlanner，语义与 P2/P3 完全一致。
 *
 * Neuron Harness 接管的部分：agent loop、function calling、多 Provider 抽象，
 * 将来 plan_step 可平滑切到 $agent->structured(CrawlPlan::class)（结构化输出）。
 */
class NeuronPlanner implements PlannerInterface
{
    /** 给 Agent 的目录候选上限 */
    private const MAX_CANDIDATES = 40;
    /** Agent 未产出可解析 JSON 时的重试次数 */
    private const MAX_PARSE_RETRY = 1;

    public function __construct(
        private Db $db,
        private Logger $log,
        private array $llmCfg,
        private array $policy,
        private ?PlannerInterface $fallback = null
    ) {
    }

    public function plan(string $intent): Plan
    {
        $candidates  = $this->catalog($intent);
        $catalogText = $this->renderCatalog($candidates);
        $targetPages = $this->extractTargetPages($intent);

        $lastRaw   = null;
        $lastError = '';
        $toolRuns  = [];
        $model     = (string)($this->llmCfg['model'] ?? '');

        try {
            for ($attempt = 0; $attempt <= self::MAX_PARSE_RETRY; $attempt++) {
                $agent = new PlannerAgent($this->db, $this->log, $this->llmCfg);

                $prompt = '用户意图：' . $intent . PHP_EOL . PHP_EOL
                    . '以下是站点真实目录候选（只读，type_id 只能从中选择）：' . PHP_EOL
                    . $catalogText . PHP_EOL . PHP_EOL
                    . '请按 System 规则：先调用 list_series 核对，再输出计划 JSON。';

                $message = $agent
                    ->chat(new UserMessage($prompt))
                    ->getMessage();

                $toolRuns = $agent->getToolRunLog();
                $rawJson  = $this->extractJson((string)$message->getContent());

                if ($rawJson !== null) {
                    $lastRaw = $rawJson;
                    break;
                }

                $lastError = 'Agent 输出不是合法 JSON: ' . substr((string)$message->getContent(), 0, 200);
                $this->log->warn('[NeuronPlanner] ' . $lastError . '，第 ' . ($attempt + 1) . ' 次重试');
            }
        } catch (Throwable $e) {
            $this->log->warn('[NeuronPlanner] Neuron Agent 执行异常，回退 LlmPlanner: ' . $e->getMessage());
            return $this->fallbackPlanner()->plan($intent);
        }

        if ($lastRaw === null) {
            $this->log->warn('[NeuronPlanner] Agent 未产出可解析计划，回退 LlmPlanner: ' . $lastError);
            return $this->fallbackPlanner()->plan($intent);
        }

        // 确定性收敛：只保留真实存在于目录的 id
        $chosen   = $this->intersectCatalog($lastRaw['type_ids'] ?? [], $candidates);
        $rejected = array_values(array_diff($lastRaw['type_ids'] ?? [], $chosen));

        $reason  = (string)($lastRaw['reason'] ?? '');
        $reasons = [];
        if ($reason !== '') {
            $reasons[] = $reason;
        }
        foreach ($chosen as $tid) {
            $reasons[] = '选中系列 id=' . $tid . '（' . ($candidates[$tid] ?? '') . '）';
        }
        if ($rejected) {
            $reasons[] = '已丢弃不在目录中的选择: ' . implode(',', $rejected);
        }
        if (!$chosen) {
            $reasons[] = '未命中可执行系列，需人工介入';
        }

        return new Plan(
            typeIds: $chosen,
            targetPages: $targetPages,
            reason: implode('；', $reasons),
            requiresApproval: true, // 写操作默认人工审批：Agent 计划永远先给人看
            metadata: [
                'planner'         => 'neuron',
                'model'           => $model,
                'tool_runs'       => $toolRuns,
                'raw'             => $lastRaw,
                'candidate_count' => count($candidates),
                'rejected'        => $rejected,
            ]
        );
    }

    // ---------------- 目录准备（与 LlmPlanner 相同语义） ----------------

    /** @return array<int,string> type_id => "中文名(English)" */
    private function catalog(string $intent): array
    {
        $out = [];
        foreach ($this->keywords($intent) as $kw) {
            foreach ($this->db->searchTypes($kw, 20) as $r) {
                $tid = (int)$r['type_id'];
                if (!isset($out[$tid])) {
                    $out[$tid] = $r['type_name']
                        . ($r['type_name_en'] !== '' ? '(' . $r['type_name_en'] . ')' : '');
                }
                if (count($out) >= self::MAX_CANDIDATES) {
                    return $out;
                }
            }
        }
        if (!$out) {
            foreach ($this->db->listTypes(self::MAX_CANDIDATES) as $r) {
                $tid = (int)$r['type_id'];
                $out[$tid] = $r['type_name']
                    . ($r['type_name_en'] !== '' ? '(' . $r['type_name_en'] . ')' : '');
            }
        }
        return $out;
    }

    /** @param array<int,string> $candidates */
    private function renderCatalog(array $candidates): string
    {
        if (!$candidates) {
            return '（站点目录暂为空，无法提供候选系列）';
        }
        $lines = [];
        foreach ($candidates as $tid => $name) {
            $lines[] = "- id={$tid} 名称={$name}";
        }
        return implode(PHP_EOL, $lines);
    }

    // ---------------- 确定性收敛 ----------------

    /** @param array<int,string> $candidates */
    private function intersectCatalog(array $typeIds, array $candidates): array
    {
        $valid = [];
        foreach (array_values(array_unique(array_filter($typeIds))) as $tid) {
            $tid = (int)$tid;
            if (isset($candidates[$tid])) {
                $valid[] = $tid;
            }
        }
        return $valid;
    }

    /** 宽容解析 JSON：去 markdown 围栏，截取首尾花括号。 */
    private function extractJson(string $content): ?array
    {
        $s = trim($content);
        if ($s === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $s, $m)) {
            $s = trim($m[1]);
        }
        $first = strpos($s, '{');
        $last  = strrpos($s, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $s = substr($s, $first, $last - $first + 1);
        }
        $json = json_decode($s, true);
        return is_array($json) ? $json : null;
    }

    private function fallbackPlanner(): PlannerInterface
    {
        return $this->fallback ?? new LlmPlanner(
            $this->db,
            $this->log,
            $this->llmCfg,
            $this->policy,
            new RulePlanner($this->db, $this->log, $this->policy)
        );
    }

    /** 简单关键词：命中中文/英文片段，供检索目录用（与 LlmPlanner 一致） */
    private function keywords(string $intent): array
    {
        $words = [];
        if (preg_match_all('/[\x{4e00}-\x{9fa5}]{2,10}/u', $intent, $m)) {
            foreach ($m[0] as $w) {
                $words[] = $w;
            }
        }
        if (preg_match_all('/[a-z0-9]+(?:[-_.][a-z0-9]+)*/i', $intent, $m)) {
            foreach ($m[0] as $w) {
                if (strlen($w) >= 3) {
                    $words[] = $w;
                }
            }
        }
        return array_values(array_unique($words));
    }

    private function extractTargetPages(string $intent): int
    {
        if (preg_match('/(\d+)\s*[页page]/ui', $intent, $m)) {
            return max(1, (int)$m[1]);
        }
        if (preg_match('/max\s*pages?\s*(\d+)/i', $intent, $m)) {
            return max(1, (int)$m[1]);
        }
        return (int)($this->policy['default_target_pages'] ?? 3);
    }
}
