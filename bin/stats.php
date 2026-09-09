<?php
declare(strict_types=1);

/**
 * 运行状态总览：Redis 任务层 + MySQL 通用结果层（crawl_jobs / crawl_records）。
 *   php bin/stats.php
 *   php bin/stats.php --source maccms   # 只看某数据源的记录
 *   php bin/stats.php --recent 5        # 额外打印最近落库的 5 条 Canonical Record
 */
use Cw\Db;
use Cw\RedisStore;

$cfg = require __DIR__ . '/../src/bootstrap.php';
$args = cw_args($argv);

$taskCfg = array_merge($cfg['task'], ['claim_idle' => $cfg['redis']['claim_idle']]);
$store = new RedisStore($cfg['redis'], $taskCfg, $cfg['redis']['prefix']);
$db = new Db($cfg['mysql']);
$source = trim((string)($args['source'] ?? ''));

echo '================ Redis 任务层 ================' . PHP_EOL;
echo ' 任务流长度  : ' . $store->streamLen() . PHP_EOL;
echo ' PEL 未确认   : ' . $store->pendingTotal() . '（in-flight/待接管重试）' . PHP_EOL;
echo ' 死信流长度  : ' . $store->deadLen() . PHP_EOL;
$stats = $store->stats();
echo ($stats ? ' 统计: ' . json_encode($stats, JSON_UNESCAPED_UNICODE) : ' 统计: (空，尚未播种)') . PHP_EOL;

echo PHP_EOL . '================ MySQL 通用结果层 ================' . PHP_EOL;
echo ' crawl_jobs    = ' . $db->countJobs($source ?: null) . PHP_EOL;
echo ' crawl_records = ' . $db->countRecords($source ?: null) . PHP_EOL;

$byEntity = $db->recordsByEntity();
foreach ($byEntity as $r) {
    printf("   %-10s %-8s rows=%d%s", $r['source'], $r['entity'], $r['cnt'], PHP_EOL);
}

echo PHP_EOL . '---- 最近作业 ----' . PHP_EOL;
foreach ($db->jobs($source ?: null, 5) as $job) {
    $scope = $job['scope_json'];
    if (is_string($scope)) {
        $scope = json_decode($scope, true) ?: [];
    }
    printf(
        "   %-24s source=%-8s status=%-7s units=%d/%d done  records=%d  scope=%s%s",
        $job['id'], $job['source'], $job['status'],
        $job['units_done'], $job['units_total'], $job['records'],
        json_encode($scope, JSON_UNESCAPED_UNICODE),
        PHP_EOL
    );
}

if (isset($args['recent']) && (int)$args['recent'] > 0) {
    $n = (int)$args['recent'];
    echo PHP_EOL . '---- 最近落库 Canonical Record ----' . PHP_EOL;
    foreach ($db->recentRecords($n, $source ?: null) as $m) {
        $payload = $m['payload_json'];
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }
        printf(
            "   %-9s | %-5s | %-10s | %-8s | %s%s",
            $m['source'], $m['entity'], $m['unit_name'],
            $m['external_id'], $m['title'],
            PHP_EOL
        );
        if ($payload) {
            echo '      payload: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }
    }
}

echo PHP_EOL . '================ MySQL 遗留表（早期 shikues 特化版本数据） ================' . PHP_EOL;
echo ' source_types   = ' . $db->countTypes() . PHP_EOL;
echo ' product_models = ' . $db->countModels() . PHP_EOL;
$byType = $db->modelsByType();
foreach ($byType as $r) {
    printf("   type %-4s %-28s rows=%d%s", $r['type_id'], $r['type_name'], $r['cnt'], PHP_EOL);
}
