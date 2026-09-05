<?php
/**
 * crawl-worker-redis 统一配置
 *
 * 所有敏感/环境相关项都支持环境变量覆盖，避免改代码：
 *   CW_MYSQL_HOST / CW_MYSQL_PORT / CW_MYSQL_USER / CW_MYSQL_PASS / CW_MYSQL_DB
 *   CW_REDIS_HOST / CW_REDIS_PORT / CW_REDIS_AUTH / CW_REDIS_PREFIX
 *   CW_SOURCE         采集源标识
 */
declare(strict_types=1);

$env = static function (string $key, $default) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
};

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
    'redis'        => [
        'host'       => $env('CW_REDIS_HOST', '127.0.0.1'),
        'port'       => (int)$env('CW_REDIS_PORT', 6379),
        'auth'       => $env('CW_REDIS_AUTH', ''),
        'timeout'    => 5.0,
        'prefix'     => $env('CW_REDIS_PREFIX', 'cw:shikues:'),
        // 消息进入消费组 PEL 后，超过该毫秒未确认即视为失联，可被接管重试。
        // 必须大于单页最坏处理时间（HTTP timeout + DB 写），默认 30s 避免误伤慢任务。
        'claim_idle' => (int)$env('CW_CLAIM_IDLE_MS', 30000),
    ],

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
];
