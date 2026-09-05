<?php
declare(strict_types=1);

/**
 * 常驻 Worker（消费组 consumer）。
 *
 * 用法：
 *   php bin/worker.php                              # 默认消费名 w1
 *   php bin/worker.php --name w2                    # 多实例：开多个终端并行消费
 *   php bin/worker.php --idle-rounds 8              # 空转 8 轮(约40s)后自动退出
 *   php bin/worker.php --idle-rounds 0              # 永不退出（常驻 daemon 化部署）
 */
use Cw\ApiClient;
use Cw\Db;
use Cw\Http;
use Cw\RedisStore;
use Cw\Worker;

$cfg = require __DIR__ . '/../src/bootstrap.php';
$args = cw_args($argv);

$consumer = (string)($args['name'] ?? 'w1');
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
