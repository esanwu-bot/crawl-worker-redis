#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Agent Orchestrator 入口：
 *   php bin/orchestrator.php run --intent "采集 MOSFET 系列" [--approve]
 *   php bin/orchestrator.php approve --id <jobId>
 *   php bin/orchestrator.php control --id <jobId> --action pause|resume|cancel
 *   php bin/orchestrator.php status --id <jobId>
 *   php bin/orchestrator.php serve --port 8787
 */

$cfg = require __DIR__ . '/../src/bootstrap.php';

use Cw\Agent\EngineCapability;
use Cw\Agent\JobIntent;
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

$args = cw_args($argv);
$cmd  = (string)($args['_pos'][0] ?? 'serve');

$log      = new Logger(dirname(__DIR__) . '/logs', 'orchestrator');
$store    = new RedisStore($cfg['redis'], $cfg['task'], $cfg['redis']['prefix']);
$http     = new Http($cfg['http']);
$api      = new ApiClient($http, $cfg['api_base'], (int)$cfg['seed']['type']);
$db       = new Db($cfg['mysql']);
$producer = new Producer($store, $api, $db, $log, $cfg['seed'], $cfg['source']);

$engine   = new EngineCapability($producer, $store, $db, $api, $log, $cfg);
$repo     = new JobRepository($db);
$planner  = PlannerFactory::create($db, $log, $cfg['agent']);
$workflow = new Workflow($planner, $engine, $repo, $log, array_merge($cfg['agent']['policy'], $cfg['worker']));

function out(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
}

try {
    switch ($cmd) {
        case 'run':
            $intent = trim((string)($args['intent'] ?? $args['i'] ?? ''));
            $id     = (string)($args['id'] ?? JobIntent::generateId());
            Validator::validateIntent($intent);
            $result = $workflow->execute($id, $intent, !empty($args['approve']));
            out($result);
            break;

        case 'approve':
            $id = (string)($args['id'] ?? '');
            if ($id === '') {
                throw new \RuntimeException('缺少 --id');
            }
            out($workflow->approve($id));
            break;

        case 'control':
            $id     = (string)($args['id'] ?? '');
            $action = (string)($args['action'] ?? '');
            if ($id === '' || $action === '') {
                throw new \RuntimeException('缺少 --id 或 --action');
            }
            out($workflow->control($id, $action));
            break;

        case 'status':
            $id = (string)($args['id'] ?? '');
            if ($id === '') {
                throw new \RuntimeException('缺少 --id');
            }
            out([
                'job'   => $repo->get($id),
                'runs'  => $repo->runsFor($id),
                'stats' => $engine->getStats(),
            ]);
            break;

        case 'serve':
            $host = (string)($args['host'] ?? '127.0.0.1');
            $port = (int)($args['port'] ?? 8787);
            $root = dirname(__DIR__) . '/ui';
            if (!is_dir($root)) {
                mkdir($root, 0755, true);
                file_put_contents($root . '/index.html', '<h1>Workbench UI 占位</h1>');
            }
            $router = __DIR__ . '/orchestrator-router.php';
            $php    = escapeshellarg(PHP_BINARY);
            $addr   = escapeshellarg("{$host}:{$port}");
            $doc    = escapeshellarg($root);
            $rout   = escapeshellarg($router);
            echo "Agent Orchestrator HTTP 服务启动: http://{$host}:{$port}/\n";
            echo "API 路由: /api/jobs, /api/stats, /api/dead\n";
            echo "按 Ctrl+C 停止\n";
            passthru("{$php} -S {$addr} -t {$doc} {$rout}");
            break;

        default:
            fwrite(STDERR, "未知命令: {$cmd}\n用法: run|approve|control|status|serve\n");
            exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    exit(1);
}
