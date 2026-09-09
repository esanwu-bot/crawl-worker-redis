<?php
declare(strict_types=1);

/**
 * LLM 网关连通性检查：
 *   php samples/llm_check.php
 *
 * 检查两项：
 *   1) GET  /models            看网关可用模型名（不打印 key）
 *   2) POST /chat/completions  最小 chat 调用验证鉴权与出参
 * 若返回 4xx，多为 model 名不受支持，按第一步列出的可用模型改 .env。
 */
$cfg = require __DIR__ . '/../src/bootstrap.php';

$llm   = $cfg['agent']['llm'];
$base  = rtrim((string)($llm['base_url'] ?? ''), '/');
$key   = (string)($llm['api_key'] ?? '');
$model = (string)($llm['model'] ?? 'gpt-4o-mini');

if ($base === '' || $key === '') {
    fwrite(STDERR, "缺少 CW_AGENT_LLM_BASE_URL / CW_AGENT_LLM_KEY（请检查 agentVersion/.env）\n");
    exit(1);
}

function httpCall(string $url, string $key, array $payload = null): array
{
    $headers = ['Authorization: Bearer ' . $key, 'Content-Type: application/json'];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, $err, is_string($body) ? $body : ''];
}

echo '== base   = ' . $base . "\n";
echo '== model  = ' . $model . "\n";

$filter   = (string)($argv[1] ?? ''); // 可选：只列出名称中含该关键字的模型
$listOnly = in_array('--list-only', $argv, true); // 只列模型，不发起 chat 调用
$override = (string)($argv[2] ?? ''); // 可选：临时切换 chat 用的模型，不改 .env
if ($override !== '') {
    $model = $override;
}

[$code, $err, $body] = httpCall($base . '/models', $key);
echo "== GET /models => HTTP {$code}\n";
if ($code !== 200) {
    echo ($err !== '' ? 'curl err: ' . $err . "\n" : '') . substr($body, 0, 500) . "\n";
} else {
    $ids = json_decode($body, true)['data'] ?? null;
    if (is_array($ids)) {
        $names = array_column($ids, 'id');
        sort($names);
        if ($filter !== '') {
            $names = array_values(array_filter(
                $names,
                static fn(string $n): bool => stripos($n, $filter) !== false
            ));
            echo "匹配 '{$filter}' 的模型 " . count($names) . " 个：\n";
        } else {
            echo '可用模型 ' . count($names) . " 个，前 40：\n";
            $names = array_slice($names, 0, 40);
        }
        echo implode("\n", $names) . "\n";
    } else {
        echo substr($body, 0, 500) . "\n";
    }
}

if ($listOnly) {
    echo "（--list-only：跳过 chat 连通测试）\n";
    exit(0);
}

// 与 LlmPlanner 相同的调用形态：response_format=json_object + temperature=0
echo "== POST /chat/completions JSON 模式 (model={$model})\n";
[$code, $err, $body] = httpCall($base . '/chat/completions', $key, [
    'model'       => $model,
    'messages'    => [
        ['role' => 'system', 'content' => '严格输出 JSON 对象，不要 Markdown 代码块，结构：{"ok":bool}'],
        ['role' => 'user',   'content' => '连通性测试'],
    ],
    'temperature' => 0,
    'response_format' => ['type' => 'json_object'],
]);
if ($code !== 200) {
    echo ($err !== '' ? 'curl err: ' . $err . "\n" : '') . substr($body, 0, 600) . "\n";
    exit(1);
}
$res = json_decode($body, true);
$content = $res['choices'][0]['message']['content'] ?? '(无内容)';
echo "助手回复: " . trim((string)$content) . "\n";
echo "OK - LLM 网关 JSON 模式可用\n";
