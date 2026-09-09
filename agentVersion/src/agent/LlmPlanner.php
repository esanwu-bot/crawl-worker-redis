<?php
declare(strict_types=1);

namespace Cw\Agent;

use Cw\Db;
use Cw\Logger;

/**
 * LLM Planner（P3）：
 *
 * 核心安全约束（与设计文档 §5 一致）：LLM 不直接产生 type_id，
 * 而是先注入「真实目录候选」（来自 source_types，只读），
 * 让 LLM 只能从候选 id 里选择。输出 JSON 再经确定性解析与目录反查，
 * 任何不在目录里的 id 一律丢弃 —— 幻觉被限制在“选择”层面。
 *
 * 规划成功/失败均有明确语义：
 *  - 命中且自洽  -> Plan (requiresApproval = true, 默认人工审批)
 *  - LLM 不可用或解析失败 -> 回退到 RulePlanner（确定性兜底）
 *  - LLM 明确表示不够清晰 -> 空选择 + 需人工
 */
class LlmPlanner implements PlannerInterface
{
    /** 给 LLM 的目录候选上限 */
    private const MAX_CANDIDATES = 40;
    /** LLM JSON 输出重试次数上限 */
    private const MAX_LLM_RETRY = 2;
    /** 瞬时繁忙（429/5xx）重试上限：网关容量抖动时避免直接回退规则 */
    private const TRANSIENT_RETRY = 3;

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
        // 1) 目录候选（闭合世界：LLM 只能在这些 id 里选）
        $candidates = $this->catalog($intent);
        $catalogText = $this->renderCatalog($candidates);
        $targetPages = $this->extractTargetPages($intent);

        // 2) LLM 调用：瞬时繁忙（429/5xx）指数退避重试，仍失败再回退规则
        try {
            $raw = null;
            for ($try = 0; $try < self::TRANSIENT_RETRY; $try++) {
                try {
                    $raw = $this->askLlm($intent, $catalogText, $targetPages);
                    break;
                } catch (AgentException $e) {
                    if (!$this->isTransient($e) || $try === self::TRANSIENT_RETRY - 1) {
                        throw $e;
                    }
                    $sleep = ($try + 1) * 2;
                    $this->log->warn(sprintf(
                        '[LlmPlanner] LLM 瞬时繁忙，%ds 后第 %d 次尝试: %s',
                        $sleep, $try + 2, $e->getMessage()
                    ));
                    sleep($sleep);
                }
            }
        } catch (AgentException $e) {
            $this->log->warn('[LlmPlanner] LLM 不可用，回退 RulePlanner: ' . $e->getMessage());
            return $this->fallback()->plan($intent);
        }

        // 3) 确定性收敛：只保留真实存在于目录的 id
        $chosen   = $this->intersectCatalog($raw['type_ids'] ?? [], $candidates);
        $rejected = $raw['type_ids'] ?? [];
        $rejected = array_values(array_diff($rejected, $chosen));

        $reason  = (string)($raw['reason'] ?? '');
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
            // 写操作默认人工审批（LLM 计划永远先给人看）
            requiresApproval: true,
            metadata: [
                'planner'   => 'llm',
                'model'     => (string)($this->llmCfg['model'] ?? ''),
                'raw'       => $raw,
                'candidate_count' => count($candidates),
                'rejected'  => $rejected,
            ]
        );
    }

    // ---------------- 目录准备 ----------------

    /**
     * 依意图关键词在 source_types 检索候选。
     * 无命中时放宽为目录前 N 条，让 LLM 仍可依据真实目录作答。
     *
     * @return array<int,string> type_id => "中文名(English)"
     */
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

    // ---------------- LLM 交互 ----------------

    /**
     * @param array<int,string> $candidates
     * @return array{type_ids:int[],reason:string}
     */
    private function askLlm(string $intent, string $catalogText, int $targetPages): array
    {
        $base   = rtrim((string)($this->llmCfg['base_url'] ?? ''), '/');
        $apiKey = (string)($this->llmCfg['api_key'] ?? '');
        if ($base === '') {
            throw new AgentException('未配置 agent.llm.base_url');
        }

        $system = '你是元器件数据采集系统的规划器。用户会用中文描述想采集的系列。'
            . '你只能从下方"真实目录"中选择 type_id，绝不能编造不存在的 id，也绝不能修改 id 含义。'
            . '规则：'
            . '1) 严格输出 JSON 对象，不要任何解释或 Markdown 代码块；'
            . '2) 结构：{"type_ids":[整数数组],"reason":"选择理由中文简述","target_pages":整数}；'
            . '3) type_ids 只能取目录中出现的 id；用户范围过大/未命中时 type_ids 给空数组并在 reason 说明；'
            . '4) target_pages 参考用户说页数；没说则用 ' . $targetPages . '。'
            . '真实目录如下：' . PHP_EOL . $catalogText;

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => '用户意图：' . $intent],
        ];

        $payload = [
            'model'       => (string)($this->llmCfg['model'] ?? ''),
            'messages'    => $messages,
            'temperature' => 0.0,
        ];
        // OpenAI 兼容网关普遍支持 response_format；Ollama /v1 也兼容
        $useJsonMode = ($this->llmCfg['json_mode'] ?? true) !== false;
        if ($useJsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $lastErr = '';
        for ($i = 0; $i < self::MAX_LLM_RETRY; $i++) {
            $content = $this->postChat($base, $apiKey, $payload);
            if ($content === null) {
                continue;
            }
            $json = $this->extractJson($content);
            if ($json !== null) {
                return [
                    'type_ids' => array_values(array_unique(array_filter(
                        array_map('intval', (array)($json['type_ids'] ?? [])),
                        static fn(int $v): bool => $v > 0
                    ))),
                    'reason'   => trim((string)($json['reason'] ?? '')),
                ];
            }
            $lastErr = '输出不是合法 JSON: ' . substr($content, 0, 200);
        }
        throw new AgentException('LLM 返回无法解析: ' . $lastErr);
    }

    /** POST /chat/completions，成功返回 content，网络/HTTP 错误抛 AgentException。 */
    private function postChat(string $base, string $apiKey, array $payload): ?string
    {
        if (!function_exists('curl_init')) {
            throw new AgentException('缺少 curl 扩展，无法调用 LLM');
        }
        $url = $base . '/chat/completions';
        $ch  = curl_init($url);
        $headers = ['Content-Type: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => (int)($this->llmCfg['timeout'] ?? 30),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false, // 兼容本地/网关自签证书；生产请开 CA
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code === 0) {
            throw new AgentException('LLM 请求失败: ' . $err);
        }
        $res = json_decode((string)$body, true);
        if ($code >= 400 || !is_array($res)) {
            $msg = is_array($res) && isset($res['error']['message'])
                ? (string)$res['error']['message']
                : substr((string)$body, 0, 300);
            throw new AgentException("LLM HTTP {$code}: {$msg}");
        }
        $content = $res['choices'][0]['message']['content'] ?? null;
        return is_string($content) ? $content : null;
    }

    /** 宽容解析 JSON：去 markdown 围栏，截取首尾花括号。 */
    private function extractJson(string $content): ?array
    {
        $s = trim($content);
        // ```json ... ```
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

    // ---------------- 确定性收敛 ----------------

    /**
     * 只保留 candidates 中的 id。
     * @param array<int,string> $candidates
     */
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

    private function fallback(): PlannerInterface
    {
        return $this->fallback ?? new RulePlanner($this->db, $this->log, $this->policy);
    }

    /** 429 / 5xx / 限流 / 繁忙 视为瞬时错误，值得退避重试 */
    private function isTransient(AgentException $e): bool
    {
        return (bool)preg_match('/HTTP (429|5\d\d)|rate_limit|busy|capacity|繁忙|容量/i', $e->getMessage());
    }

    /** 简单关键词：命中中文/英文片段，供检索目录用 */
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
