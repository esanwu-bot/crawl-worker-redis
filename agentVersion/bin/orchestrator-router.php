<?php
declare(strict_types=1);

/**
 * PHP 内置 HTTP 服务的路由器。
 * 处理 /api/*，其余路径回退到静态文件（Workbench UI）。
 */

$cfg = require __DIR__ . '/../src/bootstrap.php';

// Neuron v3 Harness 依赖：vendor 存在才加载（未 composer install 时降级旧路径）
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

// 关键：开启输出缓冲，让 json() 里的 ob_end_clean() 能真正丢弃
// Logger / PlannerFactory 等 STDOUT 噪音，避免污染 JSON 响应体。
ob_start();

use Cw\Agent\EngineCapability;
use Cw\Agent\JobRepository;
use Cw\Agent\PlannerFactory;
use Cw\Agent\Validator;
use Cw\Agent\Workflow;
use Cw\ApiClient;
use Cw\Db;
use Cw\Http;
use Cw\Logger;
use Cw\Producer;
use Cw\RedisStore;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = rtrim($uri, '/');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$body = [];
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
}

function json(array $data, int $code = 200): void
{
    // 兜底：清空任何意外 stdout（Logger / PlannerFactory INFO 等）以保证响应为纯 JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error(string $msg, int $code = 400): void
{
    json(['error' => $msg], $code);
}

$log      = new Logger(dirname(__DIR__) . '/logs', 'orchestrator-http');
$store    = new RedisStore($cfg['redis'], $cfg['task'], $cfg['redis']['prefix']);
$http     = new Http($cfg['http']);
$api      = new ApiClient($http, $cfg['api_base'], (int)$cfg['seed']['type']);
$db       = new Db($cfg['mysql']);
$producer = new Producer($store, $api, $db, $log, $cfg['seed'], $cfg['source']);

$engine   = new EngineCapability($producer, $store, $db, $api, $log, $cfg);
$repo     = new JobRepository($db);

// Planner 仅在 chat/approve/control 路径需要 → 懒加载，
// 避免 /api/health /api/stats 等无 AI 请求也被 PlannerFactory::create 污染 stdout。
$workflow = null;
$getWorkflow = static function () use (&$workflow, $db, $log, $cfg, $engine, $repo): Workflow {
    if ($workflow === null) {
        $planner = PlannerFactory::create($db, $log, $cfg['agent']);
        $workflow = new Workflow($planner, $engine, $repo, $log, array_merge($cfg['agent']['policy'], $cfg['worker']));
    }
    return $workflow;
};

try {
    // 健康检查
    if ($uri === '/api/health') {
        json(['ok' => true, 'php' => PHP_VERSION]);
    }

    // 列任务
    if ($uri === '/api/jobs' && $method === 'GET') {
        json(['jobs' => $repo->list()]);
    }

    // 创建任务 / 执行
    if ($uri === '/api/jobs' && $method === 'POST') {
        $intent = trim((string)($body['intent'] ?? ''));
        $id     = (string)($body['id'] ?? \Cw\Agent\JobIntent::generateId());
        $approve = (bool)($body['approve'] ?? false);
        Validator::validateIntent($intent);
        json($getWorkflow()->execute($id, $intent, $approve));
    }

    // 任务详情
    if (preg_match('#^/api/jobs/([a-f0-9]+)$#', $uri, $m) && $method === 'GET') {
        $job = $repo->get($m[1]);
        if (!$job) {
            error('not found', 404);
        }
        json([
            'job'   => $job,
            'runs'  => $repo->runsFor($m[1]),
            'stats' => $engine->getStats(),
        ]);
    }

    // 审批继续
    if (preg_match('#^/api/jobs/([a-f0-9]+)/approve$#', $uri, $m) && $method === 'POST') {
        json($getWorkflow()->approve($m[1]));
    }

    // 控制信号
    if (preg_match('#^/api/jobs/([a-f0-9]+)/control$#', $uri, $m) && $method === 'POST') {
        $action = (string)($body['action'] ?? '');
        json($getWorkflow()->control($m[1], $action));
    }

    // 引擎指标
    if ($uri === '/api/stats' && $method === 'GET') {
        $stats = $engine->getStats();
        // 附加工：单元进度 & Worker 心跳（前端仪表盘用）
        $stats['cursors'] = $store->cursorsOverview();
        $stats['workers'] = $store->workersOverview();
        json($stats);
    }

    // Worker 心跳详情
    if ($uri === '/api/workers' && $method === 'GET') {
        json(['workers' => $store->workersOverview()]);
    }

    // 单元进度
    if ($uri === '/api/progress' && $method === 'GET') {
        json(['cursors' => $store->cursorsOverview()]);
    }

    // 死信
    if ($uri === '/api/dead' && $method === 'GET') {
        json(['dead' => $engine->getDeadLetters()]);
    }
    if ($uri === '/api/dead/requeue' && $method === 'POST') {
        json(['requeued' => $engine->requeueDead((array)($body['ids'] ?? []))]);
    }
} catch (\Throwable $e) {
    error($e->getMessage(), 400);
}

// 非 API 路径交给内置服务器按静态文件处理
return false;
