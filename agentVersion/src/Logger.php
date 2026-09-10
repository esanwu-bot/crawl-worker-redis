<?php
declare(strict_types=1);

namespace Cw;

/**
 * 极简日志：同时输出控制台与日志文件，便于常驻 Worker 观察。
 */
class Logger
{
    private string $file;
    private bool   $verbose;

    public function __construct(string $logDir, string $name = 'worker')
    {
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $this->file    = rtrim($logDir, '/\\') . DIRECTORY_SEPARATOR . $name . '.log';
        $this->verbose = true;
    }

    public function setVerbose(bool $v): void
    {
        $this->verbose = $v;
    }

    public function info(string $msg, array $ctx = []): void
    {
        $this->write('INFO', $msg, $ctx);
    }

    public function warn(string $msg, array $ctx = []): void
    {
        $this->write('WARN', $msg, $ctx);
    }

    public function error(string $msg, array $ctx = []): void
    {
        $this->write('ERROR', $msg, $ctx);
    }

    private function write(string $level, string $msg, array $ctx): void
    {
        $line = sprintf(
            '[%s] %s %s%s',
            date('Y-m-d H:i:s'),
            $level,
            $msg,
            $ctx ? ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        ) . PHP_EOL;

        // 仅 CLI 模式下把日志推到 STDOUT（便于观察）；HTTP 进程（cli-server /
        // fpm / apache）下 STDOUT 就是响应体，echo 会污染 JSON，必须静默。
        if ($this->verbose && PHP_SAPI === 'cli') {
            echo $line;
        }
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }
}
