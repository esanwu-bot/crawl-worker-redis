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

// ---------- .env 加载（本地开发便利） ----------
// 文件位于 agentVersion/.env；真实部署请改用系统环境变量注入。
// 已存在的真实环境变量优先，不会被 .env 覆盖。
$dotenvFile = dirname(__DIR__) . '/.env';
if (is_file($dotenvFile)) {
    foreach (file($dotenvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq + 1));
        if (strlen($v) >= 2) {
            $first = $v[0];
            $last  = $v[strlen($v) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $v = substr($v, 1, -1);
            }
        }
        if (getenv($k) === false) {
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
        }
    }
}

$env = static function (string $key, $default) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
};

// Redis 连接段单独抽出：任务层 Stream 与代理池 Redis 化共用同一实例（host/auth/prefix），
// 保证代理健康状态天然多 Worker 共享、重启可恢复。
$redisConn = [
    'host'    => $env('CW_REDIS_HOST', '127.0.0.1'),
    'port'    => (int)$env('CW_REDIS_PORT', 6379),
    'auth'    => $env('CW_REDIS_AUTH', ''),
    'timeout' => 5.0,
    'prefix'  => $env('CW_REDIS_PREFIX', 'cw:shikues:'),
];

return [
    // ---------- 采集源（业务表 source 字段） ----------
    'source'       => $env('CW_SOURCE', 'shikues'),
    'site'         => 'https://www.shikues.com',
    'api_base'     => 'https://api.shikues.com/api/product',

    // ---------- MySQL（结果层：业务表全部落在这里） ----------
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
        'type'       => 1,                   // 站点产品大类：1=分立元器件
        'types'      => [],                  // 指定系列 id；为空则取 productType 前若干
        'limit'      => 3,                   // 未指定系列时最多发现的系列数
        'max_pages'  => (int)$env('CW_MAX_PAGES', 3), // 每个系列最多抓多少页（演示限速）
    ],

    // ---------- Worker ----------
    'worker'       => [
        'batch'      => 5,    // 每轮最多读多少条
        'block_sec'  => 5,    // 阻塞读秒数
        'idle_rounds'=> 30,   // 连续空转多少轮后自动退出（0=永不退出，常驻）
    ],

    // ---------- Agent 编排层 ----------
    'agent'        => [
        'llm'    => [
            'provider' => $env('CW_AGENT_LLM_PROVIDER', 'openai'), // openai / ollama / qwen ...
            'api_key'  => $env('CW_AGENT_LLM_KEY', ''),
            'base_url' => $env('CW_AGENT_LLM_BASE_URL', ''),
            'model'    => $env('CW_AGENT_LLM_MODEL', 'gpt-4o-mini'),
            'timeout'  => 30,
        ],
        'policy' => [
            'default_target_pages' => (int)$env('CW_AGENT_TARGET_PAGES', 3),
            'max_types_per_intent' => (int)$env('CW_AGENT_MAX_TYPES', 3),
            'auto_start_worker'    => $env('CW_AGENT_AUTO_WORKER', '1') === '1',
        ],
    ],
];
