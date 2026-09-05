<?php
declare(strict_types=1);

/**
 * 重置 Redis 任务层（任务流/死信/统计/游标/重试计数），用于重新演示。
 * 注意：不会清空 MySQL 结果表；配合 seed.php --force 可全量重采。
 *   php bin/reset.php
 */
use Cw\RedisStore;

$cfg = require __DIR__ . '/../src/bootstrap.php';

$store = new RedisStore($cfg['redis'], $cfg['task'], $cfg['redis']['prefix']);
$store->resetState();
echo 'Redis 任务层已重置（任务流/死信/统计/游标均已清空）' . PHP_EOL;
