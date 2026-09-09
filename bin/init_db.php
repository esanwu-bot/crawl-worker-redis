<?php
declare(strict_types=1);

/**
 * 初始化 MySQL 结果层：自动建库建表（幂等）。
 *   php bin/init_db.php
 */
use Cw\Db;

$cfg = require __DIR__ . '/../src/bootstrap.php';

$db = new Db($cfg['mysql']);
echo 'MySQL 连接成功，库: ' . $cfg['mysql']['db'] . PHP_EOL;
echo 'Schema OK（表不存在则已自动创建）' . PHP_EOL;
echo PHP_EOL . '-------- 通用层（跨数据源） --------' . PHP_EOL;
echo 'crawl_jobs    = ' . $db->countJobs() . PHP_EOL;
echo 'crawl_records = ' . $db->countRecords() . PHP_EOL;
echo PHP_EOL . '-------- 遗留表（早期 shikues 特化） --------' . PHP_EOL;
echo 'source_types   = ' . $db->countTypes() . PHP_EOL;
echo 'product_models = ' . $db->countModels() . PHP_EOL;
