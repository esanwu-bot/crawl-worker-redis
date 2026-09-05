<?php
declare(strict_types=1);

/**
 * 自动加载 + 通用参数解析，返回统一配置数组。
 * 用法：$cfg = require __DIR__ . '/bootstrap.php';
 */
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Cw\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 3)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/**
 * 极简 CLI 参数解析：支持 --k=v / --k v / --flag / 位置参数
 */
function cw_args(array $argv): array
{
    $args = array_slice($argv, 1);
    $out = ['_pos' => []];
    for ($i = 0, $n = count($args); $i < $n; $i++) {
        $a = $args[$i];
        if (str_starts_with($a, '--')) {
            $kv = explode('=', substr($a, 2), 2);
            $k  = $kv[0];
            if (count($kv) === 2) {
                $out[$k] = $kv[1];
            } elseif ($i + 1 < $n && !str_starts_with($args[$i + 1], '--')) {
                $out[$k] = $args[++$i];
            } else {
                $out[$k] = true;
            }
        } else {
            $out['_pos'][] = $a;
        }
    }
    return $out;
}

return require __DIR__ . '/../config/config.php';
