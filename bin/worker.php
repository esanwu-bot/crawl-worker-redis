<?php
declare(strict_types=1);

/**
 * 常驻 Worker（消费组 consumer）。
 *
 * 用法：
 *   php bin/worker.php                                  # 默认消费名 w-<主机名>-<进程号>，全局唯一，可直接多开
 *   php bin/worker.php --name w1                        # 自定义消费名（同一 Redis 消费组内须保持全局唯一）
 *   php bin/worker.php --idle-rounds 8                  # 空转 8 轮后自动退出
 *   php bin/worker.php --idle-rounds 0                  # 永不退出（常驻 daemon 化部署，建议配 supervisor）
 */
use Cw\ApiClient;
use Cw\Db;
use Cw\Http;
use Cw\RedisStore;
use Cw\Worker;

$cfg = require __DIR__ . '/../src/bootstrap.php';
$args = cw_args($argv);

// 消费名不传时取 w-<主机名>-<进程号>：同一消费组内 consumer 名是 Redis 全局唯一的，
// 多进程/多机器直接裸跑也不撞名（避免两个进程共用 PEL 入口导致互相 XCLAIM）。
$consumer = trim((string)($args['name'] ?? ''));
if ($consumer === '') {
    $host = function_exists('gethostname') ? gethostname() : '';
    $consumer = 'w-' . ($host !== '' ? $host : 'host') . '-' . getmypid();
}
$idleRounds = array_key_exists('idle-rounds', $args)
    ? (int)$args['idle-rounds']
    : (int)$cfg['worker']['idle_rounds'];
if (array_key_exists('batch', $args)) {
    $cfg['worker']['batch'] = max(1, (int)$args['batch']);
}

$taskCfg = array_merge($cfg['task'], $cfg['worker'], ['claim_idle' => $cfg['redis']['claim_idle']]);
$redisStore = new RedisStore($cfg['redis'], $taskCfg, $cfg['redis']['prefix']);
$http = new Http($cfg['http']);
$db = new Db($cfg['mysql']);
$api = new ApiClient($http, $cfg['api_base'], (int)$cfg['seed']['type']);

$worker = new Worker(
    $redisStore,
    $api,
    $db,
    $cfg['api_base'],
    $cfg['seed'],
    $taskCfg,
    $cfg['source']
);
$worker->start($consumer, $idleRounds);
