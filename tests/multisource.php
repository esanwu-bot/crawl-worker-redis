<?php
declare(strict_types=1);

/**
 * tests/multisource.php —— 双数据源 Adapter 契约冒烟（flag.md §11 首个里程碑）
 *
 * 两个业务域完全不同的 Adapter：
 *   - shikues：电子元器件（ApiClient 私有协议，Series→Model，行字段 a..t）
 *   - maccms ：影视（MacCMS 官方 JSON 接口，Category→VOD，行字段 vod_*）
 *
 * 验证整套通用采集协议：
 *   1. discoverUnits() 产出同一套“单元目录”，元数据落 source_types（source 列只差一个值）
 *   2. executeList() 各自产出同构 Canonical Record（flag.md §5 三件套）
 *   3. Producer 播种：建 crawl_jobs（source 只差一个值），投同一批 Task 到同一 Stream
 *   4. 同一批 Worker（通用 Runtime，零领域分支）消费两类 source 任务 → crawl_records
 *   5. 游标/断点/Job 状态机对两源一致生效
 *
 * 离线隔离：Redis 独立前缀 cw:multi:，MySQL 独立库 cw_multi_<pid>，测试结束自动清理。
 * 用法： php tests/multisource.php
 */
$cfg = require __DIR__ . '/../src/bootstrap.php';

// ---------------- 断言工具 ----------------
$GLOBALS['PASS'] = 0;
$GLOBALS['FAIL'] = 0;
$GLOBALS['FAIL_MSG'] = [];

function section(string $t): void
{
    echo PHP_EOL . str_repeat('=', 64) . PHP_EOL . '## ' . $t . PHP_EOL . str_repeat('-', 64) . PHP_EOL;
}

function check(string $name, bool $cond, string $detail = ''): bool
{
    if ($cond) {
        $GLOBALS['PASS']++;
        echo "  [PASS] {$name}" . PHP_EOL;
        return true;
    }
    $GLOBALS['FAIL']++;
    $GLOBALS['FAIL_MSG'][] = $name;
    echo "  [FAIL] {$name}" . ($detail !== '' ? "  <= {$detail}" : '') . PHP_EOL;
    return false;
}

// ---------------- 离线假数据源 ----------------

/** 假 shikues ApiClient：2 个系列，每系列 last_page=2，每页 2 行 */
class MultiFakeShikues extends \Cw\ApiClient
{
    public function __construct()
    {
    }

    public function productTypes(): array
    {
        return [
            ['id' => 101, 'cn_name' => '二极管系列', 'en_name' => 'Diode Series'],
            ['id' => 102, 'cn_name' => 'MOS系列',    'en_name' => 'MOS Series'],
        ];
    }

    public function productList(int $productTypeId, int $page, int $limit): array
    {
        $rows = [];
        for ($k = 1; $k <= 2; $k++) {
            $rows[] = [
                'id'  => $productTypeId * 100000 + $page * 1000 + $k,
                'a'   => sprintf('M%04d-P%d-%d', $productTypeId, $page, $k),
                'b'   => '40V',
                'c'   => '0.1A',
                'i'   => ($k % 2 === 0) ? 'SOD-123' : 'SMAF',
                'pdf' => 'multi-ds-' . $page,
            ];
        }
        return ['data' => $rows, 'last_page' => 2];
    }
}

/** 假 MacCMS：按分类/页码返回官方 provide/vod 结构，last_page=1 */
class MultiFakeMacHttp extends \Cw\Http
{
    public function __construct()
    {
        parent::__construct([]);
    }

    protected function request(string $url, ?array $proxy): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $page = max(1, (int)($q['pg'] ?? 1));
        $rows = [];
        for ($k = 1; $k <= 2; $k++) {
            $cls = (int)($q['t'] ?? 0);
            $rows[] = [
                'vod_id'      => $cls * 100000 + $page * 1000 + $k,
                'type_id'     => $cls,
                'type_name'   => $cls === 0 ? '全部' : '分类' . $cls,
                'vod_name'    => sprintf('示例影片 C%d-P%d-%d', $cls, $page, $k),
                'vod_pic'     => '/upload/vod/' . $cls . '-' . $k . '.jpg',
                'vod_remarks' => 'HD',
                'vod_year'    => '2024',
                'vod_area'    => '大陆',
                'vod_lang'    => '国语',
                'vod_time'    => '2024-05-01 12:00:00',
            ];
        }
        return [
            'body' => json_encode([
                'code' => 1, 'msg' => 'ok',
                'page' => $page, 'pagecount' => 1,
                'pagesize' => (int)($q['pagesize'] ?? 20),
                'record_count' => 2, 'list' => $rows,
            ], JSON_UNESCAPED_UNICODE),
            'code' => 200,
            'err'  => '',
        ];
    }
}

// ---------------- 测试环境隔离 ----------------
$prefix = 'cw:multi:';
$multiDb = 'cw_multi_' . getmypid() . '_' . mt_rand(1000, 9999);

// 先建库（PDO DSN 指定 dbname，不存在即失败）；再顺序执行 schema.sql
$pdo = new PDO(
    'mysql:host=' . $cfg['mysql']['host'] . ';port=' . $cfg['mysql']['port'],
    $cfg['mysql']['user'], $cfg['mysql']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$multiDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec("USE `{$multiDb}`");
foreach (preg_split('/;\s*\R/u', (string)file_get_contents(__DIR__ . '/../sql/schema.sql')) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || str_starts_with($stmt, '--')) {
        continue;
    }
    $pdo->exec($stmt);
}
unset($pdo);

$mysqlCfg = array_merge($cfg['mysql'], ['db' => $multiDb]);
$db = new \Cw\Db($mysqlCfg);

function mkRedis(array $cfg, int $claimIdle, string $prefix): array
{
    $r = $cfg['redis'];
    $r['prefix'] = $prefix;
    $r['claim_idle'] = $claimIdle;
    return $r;
}

function mkTask(array $cfg, int $claimIdle): array
{
    $t = array_merge($cfg['task'], $cfg['worker'],
        ['claim_idle' => $claimIdle, 'max_pages' => (int)$cfg['seed']['max_pages']]);
    $t['block_sec'] = 1;
    $t['batch']     = 5;
    return $t;
}

$store = new \Cw\RedisStore(mkRedis($cfg, 1, $prefix), mkTask($cfg, 1), $prefix);
$store->resetState();
$store = new \Cw\RedisStore(mkRedis($cfg, 1, $prefix), mkTask($cfg, 1), $prefix); // reset 删除消费组，重建

// ================= 1. Adapter 契约：discover + executeList =================
section('1. 双 Adapter 契约（discoverUnits / executeList / Canonical Record）');

$reg = new \Cw\Adapter\AdapterRegistry([
    'shikues' => new \Cw\Adapter\ShikuesAdapter(
        new MultiFakeShikues(), 'http://mock-shikues.local/api', 'shikues', 'model'
    ),
    'maccms' => new \Cw\Adapter\MacCmsAdapter(new MultiFakeMacHttp(), [
        'source'     => 'maccms',
        'site'       => 'https://mock-mac.example',
        'api_base'   => 'https://mock-mac.example/api.php/provide/vod/',
        'entity'     => 'vod',
        'page_size'  => 20,
        'units'      => [
            ['unit_id' => 0, 'unit_name' => '全部影片'],
            ['unit_id' => 1, 'unit_name' => '电影'],
        ],
        'detail_url' => '/index.php/vod/detail/id/{id}.html',
    ]),
]);
check('注册表含两个数据源', $reg->has('shikues') && $reg->has('maccms'));

$shUnits = $reg->get('shikues')->discoverUnits($db);
$macUnits = $reg->get('maccms')->discoverUnits($db);
check('shikues 发现 2 个单元(系列)', count($shUnits) === 2, 'n=' . count($shUnits));
check('maccms 发现 2 个单元(分类)', count($macUnits) === 2, 'n=' . count($macUnits));
check('单元目录同构(entity/unit_id/unit_name)',
    $shUnits[0]['entity'] === 'model' && $macUnits[0]['entity'] === 'vod'
    && ($shUnits[0]['unit_id'] ?? '') !== '' && ($macUnits[0]['unit_name'] ?? '') !== '');

$stSh = (int)$db->pdo()->query("SELECT COUNT(*) FROM source_types WHERE source='shikues'")->fetchColumn();
$stMc = (int)$db->pdo()->query("SELECT COUNT(*) FROM source_types WHERE source='maccms'")->fetchColumn();
check('source_types 两源共用，只差 source 值', $stSh === 2 && $stMc === 2, "shikues={$stSh} maccms={$stMc}");

// executeList 各自产出同构 Canonical Record
$shRes = $reg->get('shikues')->executeList(\Cw\Contract\Task::home($shUnits[0], 'shikues'));
$r0 = $shRes['records'][0] ?? [];
check('shikues executeList: page/last_page/ended 契约',
    $shRes['page'] === 1 && $shRes['last_page'] === 2 && $shRes['ended'] === false);
check('shikues record 是 Canonical(含 payload/raw)',
    ($r0['source'] ?? '') === 'shikues' && ($r0['entity'] ?? '') === 'model'
    && ($r0['external_id'] ?? '') !== '' && is_array($r0['payload']) && is_array($r0['raw'])
    && isset($r0['payload']['package'], $r0['payload']['specs']),
    json_encode($r0, JSON_UNESCAPED_UNICODE));

$macRes = $reg->get('maccms')->executeList(\Cw\Contract\Task::home($macUnits[0], 'maccms'));
$m0 = $macRes['records'][0] ?? [];
check('maccms executeList: last_page=1 直接 ended', $macRes['ended'] === true);
check('maccms record 是 Canonical(标题/图/详情URL)',
    ($m0['source'] ?? '') === 'maccms' && ($m0['entity'] ?? '') === 'vod'
    && str_starts_with($m0['title'], '示例影片')
    && str_contains($m0['image'], 'mock-mac')
    && str_contains($m0['url'], 'mock-mac')
    && isset($m0['payload']['vod_year']),
    json_encode($m0, JSON_UNESCAPED_UNICODE));

// ================= 2. Producer：同一套 Job-Task 模型，两源各建一个 Job =================
section('2. Producer 播种（crawl_jobs.source 只差一个值）');

$log = new \Cw\Logger(dirname(__DIR__) . '/logs', 'multi');
$producer = new \Cw\Producer($store, $reg, $db, $log, array_merge($cfg['seed'], ['max_pages' => 2]));

$s1 = $producer->run('shikues', ['101', '102'], false, 2);
$s2 = $producer->run('maccms', [], false, null);

check('shikues 作业播种 2 单元', $s1['seeded'] === 2 && $s1['job_id'] !== '', json_encode($s1));
check('maccms 作业播种 2 单元', $s2['seeded'] === 2 && $s2['job_id'] !== '', json_encode($s2));

$bySrc = [];
foreach ($db->jobs(null, 10) as $j) {
    $bySrc[$j['source']] = $j;
}
check('crawl_jobs 中两个 job 只差 source 字段（同一套 Job-Task 模型）',
    isset($bySrc['shikues'], $bySrc['maccms'])
    && $bySrc['shikues']['units_total'] == 2 && $bySrc['maccms']['units_total'] == 2);
check('同一 Stream 收到 4 个首页任务', $store->streamLen() === 4, 'stream_len=' . $store->streamLen());

$sample = $store->readBatch('multi-peek', 4, 0);
$sourcesInStream = array_unique(array_column(array_column($sample, 'payload'), 'source'));
sort($sourcesInStream);
check('同一批 Task 里两种 source 并存', $sourcesInStream === ['maccms', 'shikues'],
    json_encode($sourcesInStream));
foreach ($sample as $it) {
    $store->ack([$it['id']]);
}

// ================= 3. Worker：同一批通用 Worker 消费两类源 =================
section('3. 同一批 Worker（通用 Runtime，零领域分支）消费两类源 → crawl_records');

$worker = new \Cw\Worker($store, $reg, $db, mkTask($cfg, 1));
$worker->start('multi-w1', 8); // 空转 8 轮退出（期间应消费全部任务）

$recSh = (int)$db->pdo()->query("SELECT COUNT(*) FROM crawl_records WHERE source='shikues'")->fetchColumn();
$recMc = (int)$db->pdo()->query("SELECT COUNT(*) FROM crawl_records WHERE source='maccms'")->fetchColumn();
check('shikues 记录：2 系列 × 2 页 × 2 行 = 8', $recSh === 8, 'rows=' . $recSh);
check('maccms 记录：2 分类 × 1 页 × 2 行 = 4', $recMc === 4, 'rows=' . $recMc);

foreach ([['shikues', 'model', '101', '二极管系列', '2'],
          ['shikues', 'model', '102', 'MOS系列', '2'],
          ['maccms', 'vod', '0', '全部影片', '1'],
          ['maccms', 'vod', '1', '电影', '1']] as [$source, $entity, $unitId, $unitName, $donePages]) {
    $cur = $store->getCursor('cur:' . $source . ':' . $entity . ':' . $unitId);
    check("游标 {$source}:{$unitName} 完成 done_pages={$donePages}",
        ($cur['done_pages'] ?? '') === $donePages && ($cur['status'] ?? '') === 'done',
        json_encode($cur, JSON_UNESCAPED_UNICODE));
}

check('全部任务消费完：PEL=0 死信=0', $store->pendingTotal() === 0 && $store->deadLen() === 0,
    'pel=' . $store->pendingTotal() . ' dead=' . $store->deadLen());

// Job 状态机随 Worker 推进收敛到 done
$doneSh = $doneMc = false;
foreach ($db->jobs(null, 10) as $j) {
    if ($j['source'] === 'shikues') {
        $doneSh = $j['status'] === 'done' && (int)$j['units_done'] === 2;
    }
    if ($j['source'] === 'maccms') {
        $doneMc = $j['status'] === 'done' && (int)$j['units_done'] === 2;
    }
}
check('两源 crawl_jobs 状态机收敛 done', $doneSh && $doneMc);

// ================= 收尾 =================
$pass = $GLOBALS['PASS'];
$fail = $GLOBALS['FAIL'];
$db->pdo()->exec('DROP DATABASE IF EXISTS `' . $multiDb . '`');
$store->resetState();

echo PHP_EOL . str_repeat('=', 64) . PHP_EOL;
echo "双源冒烟结束: PASS={$pass} FAIL={$fail}" . PHP_EOL;
if ($fail > 0) {
    echo '失败项:' . PHP_EOL;
    foreach ($GLOBALS['FAIL_MSG'] as $m) {
        echo '  - ' . $m . PHP_EOL;
    }
    exit(1);
}
