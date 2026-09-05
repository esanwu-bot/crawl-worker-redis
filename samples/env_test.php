<?php
declare(strict_types=1);

$host = getenv('CW_REDIS_HOST') ?: '127.0.0.1';
$port = (int)(getenv('CW_REDIS_PORT') ?: 6379);
$auth = getenv('CW_REDIS_AUTH') ?: '';

echo "--- Redis ($host) ---" . PHP_EOL;
try {
    $r = new Redis();
    $r->connect($host, $port, 5.0);
    if ($auth !== '') {
        $r->auth($auth);
    }
    echo 'server = ' . $r->info('server')['redis_version'] . PHP_EOL;
    $r->del('testq');
    $id = $r->xAdd('testq', '*', ['k' => 'v1', 'n' => '1']);
    echo 'xadd1 = ' . var_export($id, true) . PHP_EOL;
    $r->xAdd('testq', '*', ['k' => 'v2', 'n' => '2']);
    echo 'xlen = ' . var_export($r->xLen('testq'), true) . PHP_EOL;
    try {
        $r->xGroup('CREATE', 'testq', 'g1', '0', true);
        echo 'group created' . PHP_EOL;
    } catch (Throwable $e) {
        echo 'group exists/warn: ' . $e->getMessage() . PHP_EOL;
    }
    $rows = $r->xReadGroup('g1', 'c1', ['testq' => '>'], 10, 1000);
    foreach ($rows['testq'] ?? [] as $mid => $fields) {
        echo "  msg $mid => " . json_encode($fields, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    $r->del('testq');
} catch (Throwable $e) {
    echo 'Redis ERR: ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
}

echo "--- MySQL ---" . PHP_EOL;
try {
    $p = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', 'root');
    echo 'mysql ok, version = ' . $p->query('SELECT VERSION()')->fetchColumn() . PHP_EOL;
} catch (Throwable $e) {
    echo 'MySQL ERR: ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
}

echo "--- DOM ---" . PHP_EOL;
echo class_exists('DOMDocument') ? 'dom ok' : 'dom MISSING';
echo PHP_EOL;
