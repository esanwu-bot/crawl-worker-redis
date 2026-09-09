<?php
/**
 * crawl-worker-redis 统一配置
 *
 * 所有敏感/环境相关项都支持环境变量覆盖，避免改代码：
 *   CW_MYSQL_HOST / CW_MYSQL_PORT / CW_MYSQL_USER / CW_MYSQL_PASS / CW_MYSQL_DB
 *   CW_REDIS_HOST / CW_REDIS_PORT / CW_REDIS_AUTH / CW_REDIS_PREFIX
 *   CW_SOURCE         采集源标识
 *   CW_PROXY_ENABLED  代理池开关（1=开，默认关，直连旁路）
 *   CW_PROXY_MODE     代理模式：pool=轮换 / static=固定第一个
 *   CW_PROXY_LIST     代理清单，逗号分隔，形如 user:pass@1.2.3.4:8080,5.6.7.8:3128
 */
declare(strict_types=1);

// ---------- .env.local（可选）----------
// 项目根可放 .env.local 记录本机/远程连接（已 gitignore，不进仓库）：
//   CW_REDIS_HOST=1.2.3.4 / CW_REDIS_AUTH=xxx / CW_MYSQL_HOST=... 等
// 规则：真实 shell 环境变量优先，.env.local 仅做兜底，避免误覆盖命令行注入。
$_localEnvFile = __DIR__ . '/../.env.local';
if (is_file($_localEnvFile)) {
    $lines = file($_localEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ((array)$lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq + 1));
        $v = trim($v, "\"'");          // 去掉可选的成对引号
        if ($k === '' || $v === '') {
            continue;
        }
        $cur = getenv($k);
        if ($cur === false || $cur === '') {
            putenv($k . '=' . $v);
            $_ENV[$k]  = $v;
            $_SERVER[$k] = $v;
        }
    }
}

$env = static function (string $key, $default) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
};

// Redis 连接段单独抽出：任务层 Stream 与代理池 Redis 化共用同一实例（host/auth/prefix），
// 保证代理健康状态天然多 Worker 共享、重启可恢复。prefix 为跨数据源通用前缀。
$redisConn = [
    'host'    => $env('CW_REDIS_HOST', '127.0.0.1'),
    'port'    => (int)$env('CW_REDIS_PORT', 6379),
    'auth'    => $env('CW_REDIS_AUTH', ''),
    'timeout' => 5.0,
    'prefix'  => $env('CW_REDIS_PREFIX', 'cw:'),
];

return [
    // ---------- 数据源目录（Source Definition） ----------
    // 每个 key 对应 Task.source / crawl_jobs.source；
    // Runtime 完全不感知数据源差异，差异全部收敛在 Adapter 层。
    'default_source' => $env('CW_SOURCE', 'shikues'),
    'sources' => [
        'shikues' => [
            'adapter'  => 'shikues',   // Adapter 工厂分派用
            'site'     => 'https://www.shikues.com',
            'api_base' => $env('CW_API_BASE', 'https://api.shikues.com/api/product'),
            'type'     => 1,            // 站点产品大类：1=分立元器件（供 ApiClient）
            'entity'   => 'model',      // 采集对象类型（Canonical Record.entity）
        ],
        'maccms' => [
            'adapter'  => 'maccms',
            'site'     => $env('CW_MACCMS_SITE', 'https://www.example-maccms.site'),
            // MacCMS 官方标准化接口（flag.md §9 V1）
            'api_base' => $env('CW_MACCMS_API_BASE',
                'https://www.example-maccms.site/api.php/provide/vod/'),
            'entity'   => 'vod',
            'page_size'=> (int)$env('CW_MACCMS_PAGE_SIZE', 20),
            // 类目目录（t=0 缺省表示不按分类，取全站分页）；
            // 接入真实站点时把示例替换为目标站点的实际分类 id/名称即可。
            'units'    => [
                ['unit_id' => 0, 'unit_name' => '全部影片'],
                ['unit_id' => 1, 'unit_name' => '电影'],
                ['unit_id' => 2, 'unit_name' => '剧集'],
            ],
            'detail_url' => '/index.php/vod/detail/id/{id}.html',
        ],
    ],

    // ---------- MySQL（结果层：通用 crawl_jobs/crawl_records，业务表全部落在这里） ----------
    // 默认连 3306；如本机并存 5.7 / 8.0，Db 会在 3306 / 3307 / 3308 之间自动探测可达端口，
    // 也可通过 CW_MYSQL_PORT 指定目标实例（MySQL 8 若监听非默认端口同样生效）。
    'mysql'        => [
        'host'    => $env('CW_MYSQL_HOST', '127.0.0.1'),
        'port'    => (int)$env('CW_MYSQL_PORT', 3306),
        'user'    => $env('CW_MYSQL_USER', 'root'),
        'pass'    => $env('CW_MYSQL_PASS', 'root'),
        'db'      => $env('CW_MYSQL_DB', 'shikues_crawler'),
        'charset' => 'utf8mb4',
    ],

    // ---------- Redis（任务层：Stream 队列 + 游标 + 统计） ----------
    // 注意：仓库不存放真实 Redis 连接凭据，默认指向本机 127.0.0.1 无密码实例；
    // 远程 Redis 请通过 CW_REDIS_HOST / CW_REDIS_AUTH 环境变量注入（无密码则留空）。
    'redis'        => array_merge($redisConn, [
        // 消息进入消费组 PEL 后，超过该毫秒未确认即视为失联，可被接管重试。
        // 必须大于单页最坏处理时间（HTTP timeout + DB 写），默认 30s 避免误伤慢任务。
        'claim_idle' => (int)$env('CW_CLAIM_IDLE_MS', 30000),
    ]),

    // ---------- HTTP 抓取 ----------
    'http'         => [
        'timeout'   => 20,
        'retries'   => 2,          // 单次任务失败内置重试次数（指数退避）
        'ua'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        'referer'   => 'https://www.shikues.com/categories/',
        'delay_ms'  => (int)$env('CW_DELAY_MS', 200),   // 请求间隔，礼貌抓取
        // HTTPS 证书：生产环境请设置 CW_CA_BUNDLE 指向 CA 包；
        // 本机（phpstudy 等）未装系统 CA 时按 insecure_fallback 自动降级
        'ssl_verify'        => (int)$env('CW_SSL_VERIFY', 1) === 1,
        'ca_bundle'         => (string)$env('CW_CA_BUNDLE', ''),
        'insecure_fallback' => true,

        // ---------- 代理 IP 池（可选，默认关闭，不影响直连链路） ----------
        // list 形如 "user:pass@1.2.3.4:8080,1.2.3.5:8080"（无认证则直接 "ip:port"）
        'proxy' => [
            'enabled'          => $env('CW_PROXY_ENABLED', '0') === '1',
            'mode'             => $env('CW_PROXY_MODE', 'pool'), // pool=轮换 / static=固定第一个
            'list'             => array_values(array_filter(array_map('trim',
                                   explode(',', (string)$env('CW_PROXY_LIST', ''))))),
            'retries'          => 2,        // A 类（代理不可用）最多连续换几个代理
            'cooldown_ms'      => 30000,    // 坏代理冷却时长(ms)，随连续失败递增
            'drop_after_fails' => 3,        // 连续失败达此阈值 → 弃用该代理
            // Redis 化：池状态落 Redis（多 Worker 共享 / 重启恢复），key 受 redis.prefix 前缀约束
            'redis'            => $redisConn,
            'key'              => 'proxy:pool',
        ],
    ],

    // ---------- 任务语义 ----------
    'task'         => [
        'stream'      => 'tasks',            // 任务流（加 prefix 后为 cw:shikues:tasks）
        'group'       => 'workers',
        'page_limit'  => 15,                 // 站点每页行数
        'max_attempts'=> 3,                  // 失败最大重试次数，超过进死信流
        'dead_stream' => 'tasks:dead',
        'stats_key'   => 'stats',            // HASH：运行统计
    ],

    // ---------- 采集范围（seed 默认值，可用 CLI 参数覆盖） ----------
    'seed'         => [
        'limit'      => 3,   // 未指定单元时最多发现的采集单元数（避免误采全站）
        'max_pages'  => (int)$env('CW_MAX_PAGES', 3), // 每个采集单元最多抓多少页（演示限速）
    ],

    // ---------- Worker ----------
    'worker'       => [
        'batch'      => 5,    // 每轮最多读多少条
        'block_sec'  => 5,    // 阻塞读秒数
        'idle_rounds'=> 30,   // 连续空转多少轮后自动退出（0=永不退出，常驻）
    ],
];
