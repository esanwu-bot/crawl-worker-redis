<?php
declare(strict_types=1);

/**
 * 运行状态总览：Redis 任务层 + MySQL 结果层。
 *   php bin/stats.php
 *   php bin/stats.php --recent 5     # 额外打印最近落库的 5 条型号
 */
use Cw\Db;
use Cw\RedisStore;

$cfg = require __DIR__ . '/../src/bootstrap.php';
$args = cw_args($argv);

$taskCfg = array_merge($cfg['task'], ['claim_idle' => $cfg['redis']['claim_idle']]);
$store = new RedisStore($cfg['redis'], $taskCfg, $cfg['redis']['prefix']);
$db = new Db($cfg['mysql']);

echo '================ Redis 任务层 ================' . PHP_EOL;
echo ' 任务流长度  : ' . $store->streamLen() . PHP_EOL;
echo ' PEL 未确认   : ' . $store->pendingTotal() . '（in-flight/待接管重试）' . PHP_EOL;
echo ' 死信流长度  : ' . $store->deadLen() . PHP_EOL;
$stats = $store->stats();
echo ($stats ? ' 统计: ' . json_encode($stats, JSON_UNESCAPED_UNICODE) : ' 统计: (空，尚未播种)') . PHP_EOL;

echo PHP_EOL . '================ MySQL 结果层 ================' . PHP_EOL;
echo ' source_types   = ' . $db->countTypes() . PHP_EOL;
echo ' product_models = ' . $db->countModels() . PHP_EOL;

$byType = $db->modelsByType();
foreach ($byType as $r) {
    printf("   type %-4s %-28s rows=%d%s", $r['type_id'], $r['type_name'], $r['cnt'], PHP_EOL);
}

if (isset($args['recent']) && (int)$args['recent'] > 0) {
    $n = (int)$args['recent'];
    echo PHP_EOL . '---- 最近落库样本 ----' . PHP_EOL;
    foreach ($db->recentModels($n) as $m) {
        $specs = $m['specs_json'];
        if (is_string($specs)) {
            $specs = json_decode($specs, true) ?: [];
        }
        printf(
            "  %-10s | %-12s | %-10s | %s%s",
            $m['model'],
            $m['type_name'],
            $m['package'] ?: '-',
            json_encode($specs, JSON_UNESCAPED_UNICODE),
            PHP_EOL
        );
    }
}
