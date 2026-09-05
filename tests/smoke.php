<?php
declare(strict_types=1);

/**
 * tests/smoke.php —— 冒烟/回归测试（不依赖 shikues 站点网络）
 *
 * 覆盖四类可自动化断言的关键语义：
 *   A. 运行环境：PHP 扩展、Redis、MySQL 连通
 *   B. 任务层(Redis)：XADD 投递 / 消费组读取 / ACK / PEL 计数 / 崩溃接管(CLAIM)
 *      幂等闸门(addlock) / 游标 Hash / 重试计数 / 死信流 / resetState
 *   C. 结果层(MySQL) + 清洗：Normalizer 字段异构与封装识别、(source,model) 幂等 upsert
 *   D. Worker 全链路（注入假 ApiClient，离线）：
 *       成功：抓页→落库→推进游标→自动追加下一页→断点守卫幂等(重复投递直接 ACK 不重复入库)
 *       失败：留在 PEL → 接管重试 3 次 → 转死信流 + 游标标记 dead + page_fail 计数
 *
 * 隔离性：Redis 使用独立 prefix（默认 cw:smoke:），MySQL 使用独立库（默认 cw_smoke_<pid>），
 *         与正式演示数据完全隔离，测试结束自动清理，可放心重复执行。
 *
 * 用法： php tests/smoke.php
 *       php tests/smoke.php --no-worker    # 跳过 Worker 全链路（只跑 A/B/C，<1s）
 */
$cfg = require __DIR__ . '/../src/bootstrap.php';
$args = cw_args($argv);
$noWorker = !empty($args['no-worker']);

// ---------------- 全局统计 ----------------
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

// ---------------- 测试环境隔离 ----------------
$prefix = 'cw:smoke:';                 // Redis 前缀，与正式 cw:shikues: 隔离
$smokeDb = 'cw_smoke_' . getmypid() . '_' . mt_rand(1000, 9999); // MySQL 独立库

$mysqlCfg = array_merge($cfg['mysql'], ['db' => $smokeDb]);

/** 构造一份测试用 Redis 配置（前缀+自定义 claim_idle） */
function mkRedis(array $cfg, int $claimIdle): array
{
    global $prefix;
    $r = $cfg['redis'];
    $r['prefix']     = $prefix;
    $r['claim_idle'] = $claimIdle;
    return $r;
}

/** 任务/Worker 参数（与 bin/worker.php 合并方式一致）；冒烟测试压短空转阻塞 */
function mkTask(array $cfg, int $claimIdle): array
{
    $t = array_merge($cfg['task'], $cfg['worker'], ['claim_idle' => $claimIdle]);
    // 空轮最多阻塞 1s 即可（block_sec=0 会变成 BLOCK 0 = 永久阻塞，禁止使用）
    $t['block_sec'] = 1;
    $t['batch']     = 5;
    return $t;
}

/**
 * 假 ApiClient：离线模拟站点接口。
 *  success 模式：返回固定每页 3 行、last_page=2；
 *  fail 模式：抛异常（模拟网络故障），每次抛前睡 150ms 让 XCLAIM 的 IDLE 闸门生效。
 */
class SmokeFakeApi extends \Cw\ApiClient
{
    public bool $fail = false;
    public int $lastPage = 2;

    public function __construct()
    {
        // 跳过父类构造（不需要真实 Http）
    }

    public function productList(int $productTypeId, int $page, int $limit): array
    {
        if ($this->fail) {
            usleep(150_000); // 保证消息 IDLE 超过 claim_idle，接管可发生
            throw new RuntimeException('smoke: 模拟网络故障');
        }
        $rows = [];
        for ($k = 1; $k <= 3; $k++) {
            // a=型号列；i/j 为部分系列携带封装名的异构列
            $rows[] = [
                'id'  => $productTypeId * 10000 + $page * 100 + $k,
                'a'   => sprintf('SM%04dP%d-%d', $productTypeId, $page, $k),
                'b'   => '50V',
                'c'   => '0.2A',
                'i'   => ($k % 2 === 0) ? 'SOD-123' : 'SMAF',
                'pdf' => 'smoke-ds-' . $page,
            ];
        }
        return ['data' => $rows, 'last_page' => $this->lastPage];
    }
}

// ---------------- 主体 ----------------
$redis = null;
$db    = null;
$exitCode = 0;

try {
    // ============ A. 环境 ============
    section('A. 运行环境');
    check('扩展 redis', extension_loaded('redis'));
    check('扩展 curl', extension_loaded('curl'));
    check('扩展 pdo_mysql', extension_loaded('pdo_mysql'));
    check('扩展 dom', class_exists('DOMDocument'));

    $redis = new \Cw\RedisStore(mkRedis($cfg, 30000), mkTask($cfg, 30000), $prefix);
    check('Redis 连通并已建立消费组', true, '(构造未抛异常)');
    $db = new \Cw\Db($mysqlCfg);
    check('MySQL 连通并自动建库建表', true, "db={$smokeDb}");

    // ============ B. 任务层 Redis 语义 ============
    section('B. 任务层(Redis)语义');

    // B1 投递与消费
    $id1 = $redis->addTask(1001, 1, '系列A');
    check('XADD 投递任务返回消息 ID', is_string($id1) && $id1 !== '', (string)$id1);
    check('任务流长度 = 1', $redis->streamLen() === 1, 'len=' . $redis->streamLen());

    $items = $redis->readBatch('smoke-c1', 5, 0);
    check('XREADGROUP 消费到 1 条', count($items) === 1, 'n=' . count($items));
    check('载荷 type_id/page 正确',
        isset($items[0]['payload']['type_id'], $items[0]['payload']['page'])
        && (int)$items[0]['payload']['type_id'] === 1001 && (int)$items[0]['payload']['page'] === 1,
        json_encode($items[0]['payload'] ?? null, JSON_UNESCAPED_UNICODE));
    check('未 ACK 时 PEL=1（in-flight）', $redis->pendingTotal() === 1, 'pel=' . $redis->pendingTotal());

    $redis->ack([$items[0]['id']]);
    check('ACK 后 PEL=0', $redis->pendingTotal() === 0, 'pel=' . $redis->pendingTotal());

    // B2 崩溃接管：消息被 crash-a 读走未 ACK（模拟其崩溃），
    // 未超时不可接管；超时(claim_idle)后新 consumer crash-b 可 XCLAIM 接管
    $id2 = $redis->addTask(1002, 1, '系列B');
    $items = $redis->readBatch('smoke-crash-a', 5, 0);
    check('崩溃场景：读走但不 ACK', count($items) === 1, 'id=' . ($items[0]['id'] ?? '?'));

    $rA = new \Cw\RedisStore(mkRedis($cfg, 30000), mkTask($cfg, 30000), $prefix);
    check('IDLE 未超时(30s 阈值)不能被接管',
        count($rA->claimBatch('smoke-crash-b', 5)) === 0);

    $rB = new \Cw\RedisStore(mkRedis($cfg, 50), mkTask($cfg, 50), $prefix); // 50ms 即可接管
    usleep(80_000); // 让消息 IDLE 超过 50ms
    $claimed = $rB->claimBatch('smoke-crash-b', 5);
    check('IDLE 超阈值后被新 consumer 接管(CLAIM)', count($claimed) === 1
        && isset($claimed[0]['payload']['type_id']) && (int)$claimed[0]['payload']['type_id'] === 1002,
        json_encode($claimed, JSON_UNESCAPED_UNICODE));
    $rB->ack([$claimed[0]['id']]);
    check('接管后 ACK，PEL=0', $rB->pendingTotal() === 0, 'pel=' . $rB->pendingTotal());

    // B3 幂等“追加下一页”闸门 addlock
    check('addlock 首次加锁成功', $redis->tryLockNextPage(1003, 2) === true);
    check('addlock 同页重复加锁被拒(NX)', $redis->tryLockNextPage(1003, 2) === false);
    check('addlock 不同页可加锁', $redis->tryLockNextPage(1003, 3) === true);
    $redis->clearTaskLocks(1003);
    check('clearTaskLocks 后同页可再加锁', $redis->tryLockNextPage(1003, 2) === true);

    // B4 游标 Hash
    $redis->initCursor(1004, '系列C');
    $redis->patchCursor(1004, ['done_pages' => '2', 'total_pages' => '5', 'rows' => '30']);
    $cur = $redis->getCursor(1004);
    check('游标存在', $redis->cursorExists(1004));
    check('游标字段正确',
        ($cur['done_pages'] ?? '') === '2' && ($cur['total_pages'] ?? '') === '5'
        && ($cur['rows'] ?? '') === '30' && ($cur['status'] ?? '') === 'pending',
        json_encode($cur, JSON_UNESCAPED_UNICODE));

    // B5 重试计数与死信流
    check('attempt 第 1 次', $redis->bumpAttempt('m1') === 1);
    check('attempt 第 2 次', $redis->bumpAttempt('m1') === 2);
    $redis->delAttempt('m1');
    check('delAttempt 后重新计数从 1 开始', $redis->bumpAttempt('m1') === 1);
    $redis->addDead(['type' => 'page', 'type_id' => 1005, 'page' => 9], 'smoke: 测试死信');
    check('死信流 +1', $redis->deadLen() === 1, 'dead=' . $redis->deadLen());
    check('普通任务流不受死信影响', $redis->streamLen() === 2, 'len=' . $redis->streamLen());

    // B6 resetState
    $redis->resetState();
    check('resetState 后任务流清空', $redis->streamLen() === 0);
    check('resetState 后死信流清空', $redis->deadLen() === 0);
    check('resetState 后游标清空', !$redis->cursorExists(1004));

    // ============ C. 结果层 MySQL + Normalizer ============
    section('C. 结果层(MySQL) + 清洗');

    $rows = \Cw\Normalizer::toModelRows([
        ['id' => 1, 'a' => '1N4148', 'b' => '75V', 'i' => 'SOD-123'],
        ['id' => 2, 'a' => 'SS34', 'b' => '40V', 'j' => 'SMAF'],   // 封装在 j 列（异构）
        ['id' => 3, 'a' => '', 'b' => '无型号应被跳过'],             // 空型号
    ], 'shikues', 1006, '测试系列', 'http://smoke.local/p');
    check('空型号行被过滤（3 入 2 出）', count($rows) === 2, 'n=' . count($rows));
    check('封装跨列识别(i 列 SOD-123)', ($rows[0]['package'] ?? '') === 'SOD-123');
    check('封装跨列识别(j 列 SMAF)', ($rows[1]['package'] ?? '') === 'SMAF');
    check('specs 原样保留字母 key', isset($rows[0]['specs']['b']) && $rows[0]['specs']['b'] === '75V');

    $db->upsertModels($rows);
    $n1 = (int)$db->pdo()->query(
        "SELECT COUNT(*) FROM product_models WHERE source='shikues' AND type_id=1006"
    )->fetchColumn();
    check('首次写入 2 行', $n1 === 2, 'rows=' . $n1);
    // 同 (source,model) 再 upsert 一次：数量不变 = 幂等
    $db->upsertModels($rows);
    $n2 = (int)$db->pdo()->query(
        "SELECT COUNT(*) FROM product_models WHERE source='shikues' AND type_id=1006"
    )->fetchColumn();
    check('重复 upsert 不新增行（唯一键幂等）', $n2 === 2, 'rows=' . $n2);
    $pkg = $db->pdo()->query(
        "SELECT package FROM product_models WHERE source='shikues' AND model='1N4148'"
    )->fetchColumn();
    check('落库 package 为清洗结果', $pkg === 'SOD-123', (string)$pkg);

    // ============ D. Worker 全链路（离线假 API） ============
    if ($noWorker) {
        echo PHP_EOL . '(跳过 Worker 全链路：--no-worker)' . PHP_EOL;
    } else {
        section('D. Worker 全链路（离线假 API）');

        // --- D1 成功链路：抓页→落库→推进游标→自动追加下一页 ---
        $redis = new \Cw\RedisStore(mkRedis($cfg, 1), mkTask($cfg, 1), $prefix); // claim_idle=1ms 仅加速空转
        $redis->addTask(9001, 1, 'Smoke Series');
        $fake = new SmokeFakeApi();
        $w = new \Cw\Worker($redis, $fake, $db, 'http://smoke.local/api', $cfg['seed'], mkTask($cfg, 1), 'shikues');
        $w->start('smoke-w1', 4);

        $cur = $redis->getCursor(9001);
        check('D1 抓完 last_page 游标 done_pages=2',
            ($cur['done_pages'] ?? '') === '2', json_encode($cur, JSON_UNESCAPED_UNICODE));
        check('D1 游标状态 done', ($cur['status'] ?? '') === 'done');
        $mcnt = (int)$db->pdo()->query(
            "SELECT COUNT(*) FROM product_models WHERE source='shikues' AND type_id=9001"
        )->fetchColumn();
        check('D1 共落库 2 页 × 3 行 = 6', $mcnt === 6, 'rows=' . $mcnt);
        check('D1 PEL 归 0', $redis->pendingTotal() === 0, 'pel=' . $redis->pendingTotal());
        $pkg = $db->pdo()->query(
            "SELECT package FROM product_models WHERE source='shikues' AND model='SM9001P1-2'"
        )->fetchColumn();
        check('D1 清洗后封装落库', $pkg === 'SOD-123', (string)$pkg);

        // --- D2 断点守卫：重复投递已完成的页 → 直接 ACK，不重复入库 ---
        $before = (int)$db->pdo()->query(
            "SELECT COUNT(*) FROM product_models WHERE source='shikues' AND type_id=9001"
        )->fetchColumn();
        $redis->addTask(9001, 1, 'Smoke Series'); // 模拟重复投递 page1
        $w2 = new \Cw\Worker($redis, $fake, $db, 'http://smoke.local/api', $cfg['seed'], mkTask($cfg, 1), 'shikues');
        $w2->start('smoke-w2', 3);
        $after = (int)$db->pdo()->query(
            "SELECT COUNT(*) FROM product_models WHERE source='shikues' AND type_id=9001"
        )->fetchColumn();
        check('D2 重复页直接 ACK，行数不变', $before === $after && $before === 6,
            "before={$before} after={$after}");
        check('D2 无残留 pending', $redis->pendingTotal() === 0);

        // --- D3 失败链路：留在 PEL → 接管重试 3 次 → 死信 ---
        $redis = new \Cw\RedisStore(mkRedis($cfg, 1), mkTask($cfg, 1), $prefix);
        $redis->resetState(); // 清掉 D1/D2 遗留统计
        $redis = new \Cw\RedisStore(mkRedis($cfg, 1), mkTask($cfg, 1), $prefix); // reset 删了消费组，需重建
        $redis->addTask(9002, 1, 'Fail Series');
        $bad = new SmokeFakeApi();
        $bad->fail = true;
        $w3 = new \Cw\Worker($redis, $bad, $db, 'http://smoke.local/api', $cfg['seed'], mkTask($cfg, 1), 'shikues');
        $w3->start('smoke-w3', 5);

        $dead = $redis->deadLen();
        $cur  = $redis->getCursor(9002);
        $stat = $redis->stats();
        check('D3 超限转死信流', $dead >= 1, 'dead=' . $dead);
        check('D3 游标标记 dead', ($cur['status'] ?? '') === 'dead', json_encode($cur, JSON_UNESCAPED_UNICODE));
        check('D3 page_fail 计数 = 3', (int)($stat['page_fail'] ?? 0) === 3,
            'page_fail=' . ($stat['page_fail'] ?? 0));
        check('D3 死信后 PEL 归 0（已 ACK）', $redis->pendingTotal() === 0, 'pel=' . $redis->pendingTotal());
        check('D3 失败任务未写入结果表', $db->pdo()->query(
            "SELECT COUNT(*) FROM product_models WHERE source='shikues' AND type_id=9002"
        )->fetchColumn() == 0);
    }
} catch (\Throwable $e) {
    echo PHP_EOL . '!! 测试执行异常: ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL
        . '   ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    $exitCode = 2;
}

// ---------------- 清理 ----------------
try {
    if ($redis) {
        $redis->resetState();
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $mysqlCfg['host'], $mysqlCfg['port']),
        $mysqlCfg['user'],
        $mysqlCfg['pass'],
        [PDO::ATTR_TIMEOUT => 3]
    );
    $pdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $smokeDb));
    echo PHP_EOL . "(清理完成：Redis prefix={$prefix}，MySQL db={$smokeDb} 已删除)" . PHP_EOL;
} catch (\Throwable $e) {
    echo PHP_EOL . '(清理警告: ' . $e->getMessage() . ')' . PHP_EOL;
}

// ---------------- 汇总 ----------------
echo PHP_EOL . str_repeat('=', 64) . PHP_EOL;
$total = $GLOBALS['PASS'] + $GLOBALS['FAIL'];
echo sprintf("结果: %d/%d 通过", $GLOBALS['PASS'], $total) . PHP_EOL;
if ($GLOBALS['FAIL_MSG']) {
    echo '失败项:' . PHP_EOL;
    foreach ($GLOBALS['FAIL_MSG'] as $m) {
        echo '  - ' . $m . PHP_EOL;
    }
}
if ($exitCode === 0 && $GLOBALS['FAIL'] > 0) {
    $exitCode = 1;
}
exit($exitCode);
