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

// ---- 一次性迁移：遗留 source_types（旧：id PK + type_name/type_name_en） ----
// schema.sql 里 source_types 已是新版通用表（(source,type_id) 复合 PK + cn_name/en_name），
// 但 CREATE TABLE IF NOT EXISTS 命中旧表会跳过新结构，导致 Adapter 写入报 Unknown column。
// 旧数据可由 shikues 重新 seed 恢复，故这里 drop & recreate。
$pdo = $db->pdo();
$hasCn = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'source_types' AND column_name = 'cn_name'"
)->fetchColumn();
if (!$hasCn) {
    $n = (int)$db->countTypes();
    $pdo->exec('DROP TABLE source_types');
    $pdo->exec("CREATE TABLE `source_types` (
      `source`     VARCHAR(32)  NOT NULL,
      `type_id`    INT          NOT NULL,
      `cn_name`    VARCHAR(120) NOT NULL DEFAULT '',
      `en_name`    VARCHAR(120) NOT NULL DEFAULT '',
      `updated_at` DATETIME     NOT NULL,
      PRIMARY KEY (`source`, `type_id`),
      KEY `idx_source` (`source`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo '[init_db] 迁移: 遗留 source_types(' . $n . ' 行) 已 drop 并按 schema.sql 重建为通用表' . PHP_EOL;
}

echo PHP_EOL . '-------- 通用层（跨数据源） --------' . PHP_EOL;
echo 'crawl_jobs    = ' . $db->countJobs() . PHP_EOL;
echo 'crawl_records = ' . $db->countRecords() . PHP_EOL;
echo PHP_EOL . '-------- 遗留表（早期 shikues 特化） --------' . PHP_EOL;
echo 'source_types   = ' . $db->countTypes() . PHP_EOL;
echo 'product_models = ' . $db->countModels() . PHP_EOL;
