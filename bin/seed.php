<?php
declare(strict_types=1);

/**
 * 播种：发现数据源采集单元并投递“首页任务”到 Redis Stream（跨数据源通用入口）。
 *
 * 用法：
 *   php bin/seed.php                                # 采 config.default_source
 *   php bin/seed.php --source maccms                # 切换数据源（config.sources 里的 key）
 *   php bin/seed.php --units 0,1,2                  # 只采指定采集单元 id（系列/分类）
 *   php bin/seed.php --units 1,3 --force            # 重置已完成游标后重新投递
 *   php bin/seed.php --limit 2                      # 未指定单元时自动取前 2 个
 *   php bin/seed.php --max-pages 3                  # 每单元最多抓 N 页（默认读配置）
 *
 * 同一批 Worker（bin/worker.php）即可消费所有数据源的任务：
 * Task.source 只差一个值，Runtime 代码零领域分支。
 */
use Cw\Adapter\AdapterFactory;
use Cw\Db;
use Cw\Logger;
use Cw\Producer;
use Cw\RedisStore;

$cfg = require __DIR__ . '/../src/bootstrap.php';
$args = cw_args($argv);
$log = new Logger(dirname(__DIR__) . '/logs', 'seed');

$redisStore = new RedisStore($cfg['redis'], $cfg['task'], $cfg['redis']['prefix']);
$db = new Db($cfg['mysql']);
$adapters = AdapterFactory::fromConfig($cfg);

// 目标数据源：--source 优先，其次 CW_SOURCE 环境变量，最后 config.default_source
$source = trim((string)($args['source'] ?? $cfg['default_source']));
if (!$adapters->has($source)) {
    $log->error('未知数据源: ' . $source . '（已配置: ' . implode(', ', $adapters->sources()) . '）');
    exit(1);
}

$units = [];
if (!empty($args['units'])) {
    $units = array_values(array_filter(array_map('trim', explode(',', (string)$args['units']))));
} elseif (!empty($args['types'])) {   // 兼容旧参数名
    $units = array_values(array_filter(array_map('trim', explode(',', (string)$args['types']))));
}
if (isset($args['max-pages'])) {
    $cfg['seed']['max_pages'] = max(1, (int)$args['max-pages']);
}
if (isset($args['limit'])) {
    $cfg['seed']['limit'] = max(1, (int)$args['limit']);
}

$producer = new Producer($redisStore, $adapters, $db, $log, $cfg['seed']);
$summary = $producer->run($source, $units, !empty($args['force']));
if (($summary['job_id'] ?? '') === '') {
    exit(1);
}
