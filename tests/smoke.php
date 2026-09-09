<?php
declare(strict_types=1);

/**
 * tests/smoke.php —— 冒烟/回归测试（不依赖 shikues 站点网络）
 *
 * 覆盖五类可自动化断言的关键语义：
 *   A. 运行环境：PHP 扩展、Redis、MySQL 连通
 *   B. 任务层(Redis)：XADD 投递 / 消费组读取 / ACK / PEL 计数 / 崩溃接管(CLAIM)
 *      幂等闸门(addlock) / 游标 Hash / 重试计数 / 死信流 / resetState
 *   C. 结果层(MySQL) + 清洗：Normalizer 字段异构与封装识别、(source,model) 幂等 upsert
 *   D. Worker 全链路（注入假 ApiClient，离线）：
 *       成功：抓页→落库→推进游标→自动追加下一页→断点守卫幂等(重复投递直接 ACK 不重复入库)
 *       失败：留在 PEL → 接管重试 3 次 → 转死信流 + 游标标记 dead + page_fail 计数
 *   E. 代理池(ProxyPool) + Http 失败归因（离线注入假传输）：
 *       E1/E2 内存池：round-robin / 认证解析 / 冷却 / 弃用 / markOk 恢复 / static 固定出口
 *       E3 Redis 化：跨"Worker"共享冷却、弃用重启持久、轮换不分配已弃用出口
 *       E4~E8 Http 归因：A 连接失败(内部换代理,预算耗尽才抛普通异常)、
 *       B 403/HTML 风控(标记出口后抛普通异常)、C 5xx(不怪罪出口)、成功 markOk
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
$prefix = 'cw:smoke:';                 // Redis 前缀，与正式 cw: 隔离
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
    $t = array_merge($cfg['task'], $cfg['worker'],
        ['claim_idle' => $claimIdle, 'max_pages' => (int)$cfg['seed']['max_pages']]);
    // 空轮最多阻塞 1s 即可（block_sec=0 会变成 BLOCK 0 = 永久阻塞，禁止使用）
    $t['block_sec'] = 1;
    $t['batch']     = 5;
    return $t;
}

/**
 * 通用首页任务（source/entity/unit 三元组，与 bin/seed 播种载荷一致）。
 * 冒烟测试断言据此校验“Runtime 零领域分支”——type_id 语义已不存在。
 */
function mkHomeTask(int $unitId, string $unitName, string $source = 'shikues', string $entity = 'model'): array
{
    return \Cw\Contract\Task::home(
        ['entity' => $entity, 'unit_id' => $unitId, 'unit_name' => $unitName],
        $source
    );
}

/** 与 Task::cursorKey 一致的游标 Hash 键 */
function curKey(int $unitId, string $source = 'shikues', string $entity = 'model'): string
{
    return 'cur:' . $source . ':' . $entity . ':' . $unitId;
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

/** 假传输 Http：离线注入任意 {body,code,err}，验证失败归因与换代理语义而不出网。 */
class SmokeHttp extends \Cw\Http
{
    /** @var array<int,array{body:string|false,code:int,err:string}> 按调用依次弹出 */
    public array $queue = [];
    public array $calls = [];   // 每次 request 实际使用的出口（null=直连）

    protected function request(string $url, ?array $proxy): array
    {
        $this->calls[] = $proxy;
        return $this->queue
            ? array_shift($this->queue)
            : ['body' => false, 'code' => 0, 'err' => 'empty queue'];
    }
}

/** 代理池 Redis 化连接参数：与任务层同一 Redis、同一隔离 prefix(cw:smoke:) */
function mkProxyRedis(array $cfg): array
{
    global $prefix;
    return [
        'host'    => $cfg['redis']['host'],
        'port'    => (int)$cfg['redis']['port'],
        'auth'    => $cfg['redis']['auth'] ?? '',
        'timeout' => (float)($cfg['redis']['timeout'] ?? 5.0),
        'prefix'  => $prefix,
    ];
}

/** 从 ProxyPool::stats() 中取指定出口的状态快照（找不到返回空数组） */
function poolEntry(\Cw\ProxyPool $pool, string $host): array
{
    foreach ($pool->stats() as $p) {
        if ($p['host'] === $host) {
            return $p;
        }
    }
    return [];
}

/**
 * 构造"内存模式代理池"的 Http 配置（离线测试用）：
 * 不带 redis 键 → ProxyPool 走内存分支，不污染/依赖共享状态。
 */
function proxyHttpCfg(array $cfg, array $extra = []): array
{
    $h = $cfg['http'];
    $h['retries']  = 0;   // 关闭 Http 指数退避，聚焦"代理预算"行为
    $h['delay_ms'] = 0;   // 关闭礼貌延迟
    $h['proxy'] = array_merge([
        'enabled'          => true,
        'mode'             => 'pool',
        'list'             => ['10.9.0.1:8080', '10.9.0.2:8080'],
        'retries'          => 0,          // A 类换代理预算
        'cooldown_ms'      => 60000,
        'drop_after_fails' => 3,
    ], $extra);
    return $h;
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
    $id1 = $redis->pushTask(mkHomeTask(1001, '系列A'));
    check('XADD 投递任务返回消息 ID', is_string($id1) && $id1 !== '', (string)$id1);
    check('任务流长度 = 1', $redis->streamLen() === 1, 'len=' . $redis->streamLen());

    $items = $redis->readBatch('smoke-c1', 5, 0);
    check('XREADGROUP 消费到 1 条', count($items) === 1, 'n=' . count($items));
    check('通用载荷 source/entity/cursor 正确',
        isset($items[0]['payload']['source'], $items[0]['payload']['entity'],
              $items[0]['payload']['cursor']['unit_id'], $items[0]['payload']['cursor']['page'])
        && $items[0]['payload']['source'] === 'shikues'
        && $items[0]['payload']['entity'] === 'model'
        && (string)$items[0]['payload']['cursor']['unit_id'] === '1001'
        && (int)$items[0]['payload']['cursor']['page'] === 1,
        json_encode($items[0]['payload'] ?? null, JSON_UNESCAPED_UNICODE));
    check('未 ACK 时 PEL=1（in-flight）', $redis->pendingTotal() === 1, 'pel=' . $redis->pendingTotal());

    $redis->ack([$items[0]['id']]);
    check('ACK 后 PEL=0', $redis->pendingTotal() === 0, 'pel=' . $redis->pendingTotal());

    // B2 崩溃接管：消息被 crash-a 读走未 ACK（模拟其崩溃），
    // 未超时不可接管；超时(claim_idle)后新 consumer crash-b 可 XCLAIM 接管
    $id2 = $redis->pushTask(mkHomeTask(1002, '系列B'));
    $items = $redis->readBatch('smoke-crash-a', 5, 0);
    check('崩溃场景：读走但不 ACK', count($items) === 1, 'id=' . ($items[0]['id'] ?? '?'));

    $rA = new \Cw\RedisStore(mkRedis($cfg, 30000), mkTask($cfg, 30000), $prefix);
    check('IDLE 未超时(30s 阈值)不能被接管',
        count($rA->claimBatch('smoke-crash-b', 5)) === 0);

    $rB = new \Cw\RedisStore(mkRedis($cfg, 50), mkTask($cfg, 50), $prefix); // 50ms 即可接管
    usleep(80_000); // 让消息 IDLE 超过 50ms
    $claimed = $rB->claimBatch('smoke-crash-b', 5);
    check('IDLE 超阈值后被新 consumer 接管(CLAIM)', count($claimed) === 1
        && isset($claimed[0]['payload']['cursor']['unit_id'])
        && (string)$claimed[0]['payload']['cursor']['unit_id'] === '1002',
        json_encode($claimed, JSON_UNESCAPED_UNICODE));
    $rB->ack([$claimed[0]['id']]);
    check('接管后 ACK，PEL=0', $rB->pendingTotal() === 0, 'pel=' . $rB->pendingTotal());

    // B3 幂等“追加下一页”闸门 addlock
    check('addlock 首次加锁成功', $redis->tryLockNextPage(curKey(1003), 2) === true);
    check('addlock 同页重复加锁被拒(NX)', $redis->tryLockNextPage(curKey(1003), 2) === false);
    check('addlock 不同页可加锁', $redis->tryLockNextPage(curKey(1003), 3) === true);
    $redis->clearTaskLocks(curKey(1003));
    check('clearTaskLocks 后同页可再加锁', $redis->tryLockNextPage(curKey(1003), 2) === true);

    // B4 游标 Hash（通用字段：source/entity/unit_id + status/done_pages/...）
    $redis->initCursor(curKey(1004), [
        'source'    => 'shikues',
        'entity'    => 'model',
        'unit_id'   => '1004',
        'unit_name' => '系列C',
    ]);
    $redis->patchCursor(curKey(1004), ['done_pages' => '2', 'total_pages' => '5', 'rows' => '30']);
    $cur = $redis->getCursor(curKey(1004));
    check('游标存在', $redis->cursorExists(curKey(1004)));
    check('游标字段正确',
        ($cur['done_pages'] ?? '') === '2' && ($cur['total_pages'] ?? '') === '5'
        && ($cur['rows'] ?? '') === '30' && ($cur['status'] ?? '') === 'pending'
        && ($cur['unit_name'] ?? '') === '系列C',
        json_encode($cur, JSON_UNESCAPED_UNICODE));

    // B5 重试计数与死信流
    check('attempt 第 1 次', $redis->bumpAttempt('m1') === 1);
    check('attempt 第 2 次', $redis->bumpAttempt('m1') === 2);
    $redis->delAttempt('m1');
    check('delAttempt 后重新计数从 1 开始', $redis->bumpAttempt('m1') === 1);
    $redis->addDead(mkHomeTask(1005, '系列X'), 'smoke: 测试死信');
    check('死信流 +1', $redis->deadLen() === 1, 'dead=' . $redis->deadLen());
    check('普通任务流不受死信影响', $redis->streamLen() === 2, 'len=' . $redis->streamLen());

    // B6 resetState
    $redis->resetState();
    check('resetState 后任务流清空', $redis->streamLen() === 0);
    check('resetState 后死信流清空', $redis->deadLen() === 0);
    check('resetState 后游标清空', !$redis->cursorExists(curKey(1004)));

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

    // ============ E. 代理池(ProxyPool) + Http 失败归因 ============
    section('E. 代理池(ProxyPool) + Http 失败归因');

    // E1 内存模式：round-robin / 认证解析 / 冷却跳过 / 到期复用 / 连续失败弃用 / markOk 恢复
    $pool = new \Cw\ProxyPool(
        ['u:p@10.1.0.1:8080', '10.1.0.2:8080', '10.1.0.3:8080'],
        ['mode' => 'pool', 'cooldown_ms' => 300, 'drop_after_fails' => 2]
    );
    check('E1 池规模=3', $pool->size() === 3, 'size=' . $pool->size());
    $e1n = $pool->next();
    check('E1 轮换第 1 项(带认证解析)',
        is_array($e1n) && $e1n['host'] === '10.1.0.1:8080' && $e1n['auth'] === 'u:p',
        json_encode($e1n, JSON_UNESCAPED_SLASHES));
    check('E1 轮换第 2 项', ($pool->next()['host'] ?? '') === '10.1.0.2:8080');
    $pool->markBad('10.1.0.1:8080', 'refused');
    check('E1 冷却出口被跳过 → 第 3 项', ($pool->next()['host'] ?? '') === '10.1.0.3:8080');
    usleep(350_000); // 等 300ms 冷却到期
    check('E1 冷却到期后重新分配 .1', ($pool->next()['host'] ?? '') === '10.1.0.1:8080');
    $pool->markBad('10.1.0.1:8080', 'x');   // fail=2 → 达弃用阈值
    $pool->markBad('10.1.0.1:8080', 'y');   // 已弃用仍累计
    check('E1 连续失败达阈值后可用数=2', $pool->countAvailable() === 2,
        'avail=' . $pool->countAvailable());
    check('E1 弃用状态 dropped', (poolEntry($pool, '10.1.0.1:8080')['status'] ?? '') === 'dropped');
    check('E1 stats 含 3 项', count($pool->stats()) === 3);
    $pool->markOk('10.1.0.1:8080');
    check('E1 markOk 解除弃用，可用数回到 3', $pool->countAvailable() === 3,
        'avail=' . $pool->countAvailable());

    // E2 static 模式：固定第一项；唯一出口不可用 → next()=null（本次请求直连兜底）
    $sp = new \Cw\ProxyPool(
        ['10.2.0.1:8080', '10.2.0.2:8080'],
        ['mode' => 'static', 'cooldown_ms' => 300, 'drop_after_fails' => 2]
    );
    check('E2 static 固定第一项',
        ($sp->next()['host'] ?? '') === '10.2.0.1:8080'
        && ($sp->next()['host'] ?? '') === '10.2.0.1:8080');
    $sp->markBad('10.2.0.1:8080', 'refused');
    check('E2 static 出口冷却 → next()=null(直连兜底)', $sp->next() === null);

    // E3 Redis 化：跨实例共享冷却 / 弃用重启后仍持久 / 轮换不分配已弃用出口
    $pRedis = mkProxyRedis($cfg);
    $rk = 'proxy:smoke';
    (new \Cw\ProxyPool([], ['redis' => $pRedis, 'key' => $rk]))->clear(); // 幂等重跑
    $w1 = new \Cw\ProxyPool(
        ['10.3.0.1:8080', '10.3.0.2:8080', '10.3.0.3:8080'],
        ['mode' => 'pool', 'redis' => $pRedis, 'key' => $rk,
         'cooldown_ms' => 600, 'drop_after_fails' => 2]
    );
    check('E3 Redis 池注册 3 个出口', $w1->size() === 3, 'size=' . $w1->size());
    $w1->markBad('10.3.0.1:8080', 'refused');
    // 注意：集群内各 Worker 配置一致（阈值/冷却随 opts 透传），"重启"只需携带相同池配置
    $w2 = new \Cw\ProxyPool([], ['redis' => $pRedis, 'key' => $rk,
        'cooldown_ms' => 600, 'drop_after_fails' => 2]);
    check('E3 跨实例共享冷却状态(可用=2)', $w2->countAvailable() === 2,
        'avail=' . $w2->countAvailable());
    check('E3 新实例能读到失败计数 fail=1',
        (poolEntry($w2, '10.3.0.1:8080')['fail'] ?? 0) === 1);
    usleep(700_000); // 等 600ms 冷却到期
    check('E3 冷却到期(跨实例)后可用=3', $w2->countAvailable() === 3);
    $w2->markBad('10.3.0.2:8080', 'x');
    $w2->markBad('10.3.0.2:8080', 'y'); // fail=2 → 弃用
    $w3 = new \Cw\ProxyPool([], ['redis' => $pRedis, 'key' => $rk,
        'cooldown_ms' => 600, 'drop_after_fails' => 2]); // 再重启一次
    check('E3 重启后弃用状态持久(dropped)',
        (poolEntry($w3, '10.3.0.2:8080')['status'] ?? '') === 'dropped');
    check('E3 重启后可用数=2(仅 .1/.3)', $w3->countAvailable() === 2,
        'avail=' . $w3->countAvailable());
    $seen = [];
    for ($i = 0; $i < 6; $i++) {
        $e3n = $w3->next();
        if ($e3n) {
            $seen[$e3n['host']] = true;
        }
    }
    check('E3 轮换不分配已弃用出口',
        !isset($seen['10.3.0.2:8080'])
        && isset($seen['10.3.0.1:8080'], $seen['10.3.0.3:8080']),
        json_encode(array_keys($seen), JSON_UNESCAPED_SLASHES));
    $w3->clear();

    // E4 A 类(连接失败)：内部换代理重试，预算耗尽后才暴露普通 RuntimeException
    $hA = new SmokeHttp(proxyHttpCfg($cfg, ['retries' => 1])); // 换代理预算=1
    $hA->queue = [
        ['body' => false, 'code' => 0, 'err' => 'Connection refused'],
        ['body' => false, 'code' => 0, 'err' => 'Connection refused'],
    ];
    $errA = null;
    try {
        $hA->getJson('http://api.test/x');
    } catch (\Throwable $e) {
        $errA = $e;
    }
    check('E4 A类最终抛普通异常(非 ProxyException)',
        $errA instanceof \RuntimeException && !($errA instanceof \Cw\ProxyException));
    check('E4 内部已换代理重试(两次出口不同)',
        ($hA->calls[0]['host'] ?? '') === '10.9.0.1:8080'
        && ($hA->calls[1]['host'] ?? '') === '10.9.0.2:8080',
        json_encode($hA->calls, JSON_UNESCAPED_SLASHES));
    check('E4 两个出口均被标记冷却(可用=0)',
        $hA->pool() !== null && $hA->pool()->countAvailable() === 0,
        'avail=' . ($hA->pool()?->countAvailable() ?? -1));

    // E5 B 类 403：视为风控嫌疑 → 标记出口，抛普通异常交任务层重试
    $hB = new SmokeHttp(proxyHttpCfg($cfg, []));
    $hB->queue = [['body' => 'Forbidden', 'code' => 403, 'err' => '']];
    $errB = null;
    try {
        $hB->getJson('http://api.test/y');
    } catch (\Throwable $e) {
        $errB = $e;
    }
    check('E5 403 → 普通 RuntimeException', $errB instanceof \RuntimeException);
    check('E5 403 出口被标记(fail=1)',
        (poolEntry($hB->pool(), '10.9.0.1:8080')['fail'] ?? 0) === 1);
    check('E5 未误伤其它出口(可用=1)', $hB->pool()->countAvailable() === 1,
        'avail=' . $hB->pool()->countAvailable());

    // E6 C 类 5xx：目标/业务错误，不怪罪出口
    $hC = new SmokeHttp(proxyHttpCfg($cfg, []));
    $hC->queue = [['body' => 'oops', 'code' => 500, 'err' => '']];
    $errC = null;
    try {
        $hC->getJson('http://api.test/z');
    } catch (\Throwable $e) {
        $errC = $e;
    }
    check('E6 500 → 普通 RuntimeException', $errC instanceof \RuntimeException);
    check('E6 5xx 不标记出口(fail=0)',
        (poolEntry($hC->pool(), '10.9.0.1:8080')['fail'] ?? -1) === 0);
    check('E6 出口仍全部可用(可用=2)', $hC->pool()->countAvailable() === 2,
        'avail=' . $hC->pool()->countAvailable());

    // E7 B 类 HTML 风控页：形状校验命中 → 标记出口并抛普通异常
    $hH = new SmokeHttp(proxyHttpCfg($cfg, []));
    $hH->queue = [[
        'body' => '<html><body>verify you are human</body></html>',
        'code' => 200,
        'err'  => '',
    ]];
    $errH = null;
    try {
        $hH->getJson('http://api.test/w');
    } catch (\Throwable $e) {
        $errH = $e;
    }
    check('E7 HTML 风控页 → 抛普通异常且提示 HTML',
        $errH instanceof \RuntimeException && str_contains($errH->getMessage(), 'HTML'));
    check('E7 HTML 命中出口被标记(fail=1)',
        (poolEntry($hH->pool(), '10.9.0.1:8080')['fail'] ?? 0) === 1);

    // E8 成功链路：markOk 保持出口健康
    $hOK = new SmokeHttp(proxyHttpCfg($cfg, []));
    $hOK->queue = [['body' => '{"ok":1}', 'code' => 200, 'err' => '']];
    $jsonOK = $hOK->getJson('http://api.test/v');
    check('E8 成功返回 JSON', ($jsonOK['ok'] ?? 0) === 1);
    check('E8 成功后出口 fail=0 且可用=2',
        (poolEntry($hOK->pool(), '10.9.0.1:8080')['fail'] ?? -1) === 0
        && $hOK->pool()->countAvailable() === 2,
        'avail=' . $hOK->pool()->countAvailable());

    // ============ D. Worker 全链路（离线假 Adapter，通用 Runtime） ============
    if ($noWorker) {
        echo PHP_EOL . '(跳过 Worker 全链路：--no-worker)' . PHP_EOL;
    } else {
        section('D. Worker 全链路（离线假 API，通用 Runtime）');

        /** 通用 Worker：注册假 ApiClient 的 shikues Adapter，验证 Runtime 零领域分支 */
        $mkWorker = function (\Cw\RedisStore $s, \Cw\ApiClient $api) use ($db, $cfg): \Cw\Worker {
            $adapter = new \Cw\Adapter\ShikuesAdapter($api, 'http://smoke.local/api', 'shikues', 'model');
            $reg = new \Cw\Adapter\AdapterRegistry(['shikues' => $adapter]);
            return new \Cw\Worker($s, $reg, $db, mkTask($cfg, 1));
        };

        // --- D1 成功链路：抓页→落 crawl_records→推进游标→自动追加下一页 ---
        $redis = new \Cw\RedisStore(mkRedis($cfg, 1), mkTask($cfg, 1), $prefix); // claim_idle=1ms 仅加速空转
        $redis->pushTask(mkHomeTask(9001, 'Smoke Series'));
        $fake = new SmokeFakeApi();
        $w = $mkWorker($redis, $fake);
        $w->start('smoke-w1', 4);

        $cur = $redis->getCursor(curKey(9001));
        check('D1 抓完 last_page 游标 done_pages=2',
            ($cur['done_pages'] ?? '') === '2', json_encode($cur, JSON_UNESCAPED_UNICODE));
        check('D1 游标状态 done', ($cur['status'] ?? '') === 'done');
        $mcnt = (int)$db->pdo()->query(
            "SELECT COUNT(*) FROM crawl_records WHERE source='shikues' AND entity='model' AND unit_id='9001'"
        )->fetchColumn();
        check('D1 共落库 2 页 × 3 行 = 6', $mcnt === 6, 'rows=' . $mcnt);
        check('D1 PEL 归 0', $redis->pendingTotal() === 0, 'pel=' . $redis->pendingTotal());
        $row = $db->pdo()->query(
            "SELECT title, payload_json FROM crawl_records
              WHERE source='shikues' AND entity='model' AND unit_id='9001' AND external_id='90010102'"
        )->fetch(PDO::FETCH_ASSOC);
        $pay = $row ? json_decode($row['payload_json'], true) : null;
        check('D1 Canonical title 正确', ($row['title'] ?? '') === 'SM9001P1-2',
            ($row['title'] ?? '') . ' <= title');
        check('D1 payload 含清洗结果(package=SOD-123)',
            ($pay['package'] ?? '') === 'SOD-123', json_encode($pay, JSON_UNESCAPED_UNICODE));
        check('D1 raw 原样保留', isset($pay['specs']['b']));

        // --- D2 断点守卫：重复投递已完成的页 → 直接 ACK，不重复入库 ---
        $before = (int)$db->pdo()->query(
            "SELECT COUNT(*) FROM crawl_records WHERE source='shikues' AND entity='model' AND unit_id='9001'"
        )->fetchColumn();
        $redis->pushTask(mkHomeTask(9001, 'Smoke Series')); // 模拟重复投递 page1
        $w2 = $mkWorker($redis, $fake);
        $w2->start('smoke-w2', 3);
        $after = (int)$db->pdo()->query(
            "SELECT COUNT(*) FROM crawl_records WHERE source='shikues' AND entity='model' AND unit_id='9001'"
        )->fetchColumn();
        check('D2 重复页直接 ACK，行数不变', $before === $after && $before === 6,
            "before={$before} after={$after}");
        check('D2 无残留 pending', $redis->pendingTotal() === 0);

        // --- D3 失败链路：留在 PEL → 接管重试 3 次 → 死信 ---
        $redis = new \Cw\RedisStore(mkRedis($cfg, 1), mkTask($cfg, 1), $prefix);
        $redis->resetState(); // 清掉 D1/D2 遗留统计
        $redis = new \Cw\RedisStore(mkRedis($cfg, 1), mkTask($cfg, 1), $prefix); // reset 删了消费组，需重建
        $redis->pushTask(mkHomeTask(9002, 'Fail Series'));
        $bad = new SmokeFakeApi();
        $bad->fail = true;
        $w3 = $mkWorker($redis, $bad);
        $w3->start('smoke-w3', 5);

        $dead = $redis->deadLen();
        $cur  = $redis->getCursor(curKey(9002));
        $stat = $redis->stats();
        check('D3 超限转死信流', $dead >= 1, 'dead=' . $dead);
        check('D3 游标标记 dead', ($cur['status'] ?? '') === 'dead', json_encode($cur, JSON_UNESCAPED_UNICODE));
        check('D3 page_fail 计数 = 3', (int)($stat['page_fail'] ?? 0) === 3,
            'page_fail=' . ($stat['page_fail'] ?? 0));
        check('D3 死信后 PEL 归 0（已 ACK）', $redis->pendingTotal() === 0, 'pel=' . $redis->pendingTotal());
        check('D3 失败任务未写入结果表', $db->pdo()->query(
            "SELECT COUNT(*) FROM crawl_records WHERE source='shikues' AND entity='model' AND unit_id='9002'"
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
