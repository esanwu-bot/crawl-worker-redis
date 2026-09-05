<?php
declare(strict_types=1);

/**
 * 播种：发现站点产品系列并投递“首页任务”到 Redis Stream。
 *
 * 用法：
 *   php bin/seed.php
 *   php bin/seed.php --types 1,3,4          # 只采指定系列
 *   php bin/seed.php --limit 5               # 未指定时自动取 id 升序前 5 个
 *   php bin/seed.php --force                 # 重置已完成游标重新投递
 *   php bin/seed.php --max-pages 3           # 每系列最多抓 N 页（默认读配置）
 */
use Cw\ApiClient;
use Cw\Db;
use Cw\Http;
use Cw\Logger;
use Cw\Producer;
use Cw\RedisStore;

$cfg = require __DIR__ . '/../src/bootstrap.php';
$args = cw_args($argv);
$log = new Logger(dirname(__DIR__) . '/logs', 'seed');

$redisStore = new RedisStore($cfg['redis'], $cfg['task'], $cfg['redis']['prefix']);
$http = new Http($cfg['http']);
$db = new Db($cfg['mysql']);
$api = new ApiClient($http, $cfg['api_base'], (int)$cfg['seed']['type']);

$types = [];
if (!empty($args['types'])) {
    $types = array_values(array_filter(array_map('intval', explode(',', (string)$args['types']))));
}
if (isset($args['max-pages'])) {
    $cfg['seed']['max_pages'] = max(1, (int)$args['max-pages']);
}
if (isset($args['limit'])) {
    $cfg['seed']['limit'] = max(1, (int)$args['limit']);
}

$producer = new Producer($redisStore, $api, $db, $log, $cfg['seed'], $cfg['source']);
$producer->run($types, !empty($args['force']));
