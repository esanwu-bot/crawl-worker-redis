<?php
declare(strict_types=1);

namespace Cw\Agent;

use Cw\Db;
use Cw\Logger;

/**
 * 确定性规则 Planner（P2）：不依赖 LLM，用关键词匹配本地已知的 source_types。
 * 命中系列足够明确时直接通过；否则标记 requiresApproval=true，等待人工确认。
 */
class RulePlanner implements PlannerInterface
{
    public function __construct(
        private Db $db,
        private Logger $log,
        private array $policy
    ) {
    }

    public function plan(string $intent): Plan
    {
        $intent      = trim($intent);
        $targetPages = $this->extractTargetPages($intent);
        $keywords    = $this->extractKeywords($intent);

        $this->log->info('[RulePlanner] 意图: ' . $intent . ' 关键词: ' . implode(',', $keywords));

        $typeIds = [];
        $reasons = [];
        $max     = (int)($this->policy['max_types_per_intent'] ?? 3);

        foreach ($keywords as $kw) {
            $rows = $this->db->searchTypes($kw, $max);
            foreach ($rows as $r) {
                $tid = (int)$r['type_id'];
                if (!in_array($tid, $typeIds, true)) {
                    $typeIds[] = $tid;
                    $reasons[] = "关键词「{$kw}」命中系列 {$r['type_name']}({$tid})";
                    if (count($typeIds) >= $max) {
                        break 2;
                    }
                }
            }
        }

        $requiresApproval = count($typeIds) === 0 || count($typeIds) > $max;

        return new Plan(
            typeIds: $typeIds,
            targetPages: $targetPages,
            reason: implode('；', $reasons) ?: '未匹配到已知系列',
            requiresApproval: $requiresApproval,
            metadata: ['keywords' => $keywords, 'policy' => $this->policy]
        );
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

    /**
     * 提取候选关键词：英文/数字/型号 + 中文连续词。
     */
    private function extractKeywords(string $intent): array
    {
        $intent = mb_strtolower($intent, 'UTF-8');
        $words  = [];

        // 英文、数字、型号（如 1N4148、MOSFET、2SC1815）
        if (preg_match_all('/[a-z0-9]+(?:[-_.][a-z0-9]+)*/i', $intent, $m)) {
            foreach ($m[0] as $w) {
                if (strlen($w) >= 2) {
                    $words[] = $w;
                }
            }
        }

        // 中文 2-10 字词
        if (preg_match_all('/[\x{4e00}-\x{9fa5}]{2,10}/u', $intent, $m)) {
            foreach ($m[0] as $w) {
                $words[] = $w;
            }
        }

        $words = array_values(array_unique($words));

        // 兜底：把整句也作为关键词，避免分词失败时完全空白
        if (!$words && $intent !== '') {
            $words[] = $intent;
        }

        return $words;
    }
}
