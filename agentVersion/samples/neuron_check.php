#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Neuron v3 Harness 节点连通性检查（P3 接线验证）：
 *   php samples/neuron_check.php [意图文本] [--json]
 *
 * 全链路：
 *   config(.env SenseNova) -> PlannerFactory(NeuronPlanner) -> PlannerAgent(Neuron Agent)
 *   -> OpenAILike(SenseNova 网关) -> list_series 工具往返 -> JSON 计划 -> 确定性收敛 Plan
 *
 * 无 --json 时输出带审计信息的人类可读报告。
 */

$cfg = require __DIR__ . '/../src/bootstrap.php';

// 1) Harness 是否在位
$hasVendor = is_file(__DIR__ . '/../vendor/autoload.php');
if (!$hasVendor) {
    fwrite(STDERR, "缺少 vendor/（请先 composer install，接入 neuron-core/neuron-ai）\n");
    exit(2);
}
require __DIR__ . '/../vendor/autoload.php';

use Cw\Agent\PlannerFactory;
use Cw\Db;
use Cw\Logger;

$llm = $cfg['agent']['llm'];
if (rtrim((string)($llm['base_url'] ?? ''), '/') === '' || (string)($llm['api_key'] ?? '') === '') {
    fwrite(STDERR, "缺少 CW_AGENT_LLM_BASE_URL / CW_AGENT_LLM_KEY（请检查 agentVersion/.env）\n");
    exit(1);
}

$jsonOut = in_array('--json', $argv, true);
$intent  = trim((string)($argv[1] ?? '把肖特基低正向压降那个系列整个采下来，先采 3 页'));
if ($intent === '') {
    fwrite(STDERR, "意图为空\n");
    exit(1);
}

$db  = new Db($cfg['mysql']);
$log = new Logger(dirname(__DIR__) . '/logs', 'neuron-check');

echo '== Harness : Neuron v3 (' . (class_exists('NeuronAI\Agent\Agent') ? '已安装' : '未安装') . ')' . PHP_EOL;
echo '== Gateway : ' . rtrim((string)$llm['base_url'], '/') . '  model=' . (string)$llm['model'] . PHP_EOL;
echo '== Intent  : ' . $intent . PHP_EOL;

$planner = PlannerFactory::create($db, $log, $cfg['agent']);
echo '== Planner : ' . $planner::class . PHP_EOL;

$start = microtime(true);
$plan  = $planner->plan($intent);
$cost  = number_format(microtime(true) - $start, 2);

if ($jsonOut) {
    echo json_encode(
        $plan->toArray(),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ) . PHP_EOL;
} else {
    echo PHP_EOL . '== 计划（耗时 ' . $cost . 's，requires_approval=' . var_export($plan->requiresApproval, true) . '）' . PHP_EOL;
    echo 'type_ids    : [' . implode(', ', $plan->typeIds) . ']' . PHP_EOL;
    echo 'target_pages: ' . $plan->targetPages . PHP_EOL;
    echo 'reason      : ' . $plan->reason . PHP_EOL;
    echo 'metadata    : ' . json_encode($plan->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

// 语义自检
$errors = [];
if (($plan->metadata['planner'] ?? '') !== 'neuron') {
    $errors[] = '期望 NeuronPlanner 产出（metadata.planner=neuron），实际 = ' . ($plan->metadata['planner'] ?? '无');
}
$toolRuns = $plan->metadata['tool_runs'] ?? [];
if (($plan->metadata['planner'] ?? '') === 'neuron' && $toolRuns === []) {
    $errors[] = 'Neuron Agent 未发生任何工具调用（list_series 未被执行）';
}

if ($errors) {
    fwrite(STDERR, PHP_EOL . "自检失败：\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo PHP_EOL . 'OK - Neuron v3 节点接线可用，工具调用 ' . count($toolRuns) . ' 次' . PHP_EOL;
exit(0);
