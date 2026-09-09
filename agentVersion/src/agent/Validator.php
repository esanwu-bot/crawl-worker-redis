<?php
declare(strict_types=1);

namespace Cw\Agent;

/**
 * 意图输入的基础校验与安全兜底。
 */
class Validator
{
    private const BLOCKED = ['rm -rf', 'drop table', 'delete from', 'truncate ', '--;', '/*'];

    public static function validateIntent(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            throw new AgentException('意图不能为空');
        }
        if (mb_strlen($text, 'UTF-8') > 500) {
            throw new AgentException('意图长度不能超过 500 字');
        }
        foreach (self::BLOCKED as $b) {
            if (stripos($text, $b) !== false) {
                throw new AgentException('意图包含非法指令片段');
            }
        }
    }
}
