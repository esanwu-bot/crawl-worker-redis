<?php
declare(strict_types=1);

namespace Cw\Contract;

/**
 * 通用任务载荷契约（Redis Stream 消息的 p 字段内容）。
 *
 * Runtime（Producer / Worker / RedisStore）只理解这里的结构；
 * 任何数据源特定语义（协议、字段、分页参数）都封装在 SourceAdapter 内。
 *
 * 载荷示例（与 flag.md §4 对齐）：
 * {
 *   "job_id":    "job_xxx",
 *   "source":    "shikues" | "maccms",
 *   "entity":    "model" | "vod",        // 采集对象类型
 *   "operation": "list",
 *   "cursor":    { "unit_id": "3", "unit_name": "肖特基二极管", "page": 1 },
 *   "params":    { "limit": 15, "t": 1 },  // adapter 专用，可缺省
 *   "target_pages": 3                       // 运行时策略（可缺省）
 * }
 *
 * cursor 是一个“可独立消费的游标单元”：
 *   - 一个 cursor = (source, entity, unit) 的某一次分页采集；
 *   - page 由 Worker 逐页推进，unit_id/unit_name 用于定位游标 Hash 与可读日志。
 */
final class Task
{
    /** 列表采集操作（V1 只做 list；detail/export 走 operation 扩展） */
    public const OP_LIST = 'list';

    /**
     * 由播种发现的 unit 生成首页任务。
     *
     * @param array  $unit         ['entity','unit_id','unit_name','params'?(可选)]
     * @param string $source       数据源标识
     * @param int    $page         起始页（默认 1）
     * @param int|null $targetPages 运行时抓取上限；null 表示由 Worker 读全局配置
     * @param string $jobId        所属 crawl_jobs 主键（可空）
     * @return array 载荷
     */
    public static function home(array $unit, string $source, int $page = 1, ?int $targetPages = null, ?string $jobId = null): array
    {
        $t = [
            'source'    => $source,
            'entity'    => (string)($unit['entity'] ?? ''),
            'operation' => self::OP_LIST,
            'cursor'    => [
                'unit_id'   => (string)($unit['unit_id'] ?? ''),
                'unit_name' => (string)($unit['unit_name'] ?? ''),
                'page'      => max(1, $page),
            ],
        ];
        $params = (array)($unit['params'] ?? []);
        if ($params) {
            $t['params'] = $params;
        }
        if ($targetPages !== null && $targetPages > 0) {
            $t['target_pages'] = $targetPages;
        }
        if ($jobId !== null && $jobId !== '') {
            $t['job_id'] = $jobId;
        }
        return $t;
    }

    /** 生成下一页任务（复用同 unit 的源/实体/参数，仅推进 page） */
    public static function next(array $task): array
    {
        $next = $task;
        $next['cursor']['page'] = ((int)($task['cursor']['page'] ?? 1)) + 1;
        return $next;
    }

    /** 校验载荷是否为一个合法的 list 任务 */
    public static function isValidList(array $p): bool
    {
        return isset($p['source'], $p['entity'], $p['operation'], $p['cursor'])
            && (string)$p['operation'] === self::OP_LIST
            && (string)($p['source'] ?? '') !== ''
            && (string)($p['entity'] ?? '') !== ''
            && (string)($p['cursor']['unit_id'] ?? '') !== ''
            && (int)($p['cursor']['page'] ?? 0) > 0;
    }

    /** 游标 Hash 键：cur:{source}:{entity}:{unit_id}（Reset/运维可统一 SCAN） */
    public static function cursorKey(array $p): string
    {
        return 'cur:' . $p['source'] . ':' . $p['entity'] . ':' . $p['cursor']['unit_id'];
    }

    /** 幂等“追加下一页”闸门键：addlock:{source}:{entity}:{unit_id}:{page} */
    public static function nextPageLockKey(array $p): string
    {
        return 'addlock:' . $p['source'] . ':' . $p['entity'] . ':' . $p['cursor']['unit_id'] . ':' . (int)$p['cursor']['page'];
    }

    /** 可读日志描述：source[unit_name] p{page} */
    public static function describe(array $p): string
    {
        $unit = (string)($p['cursor']['unit_name'] ?? $p['cursor']['unit_id'] ?? '');
        return sprintf('[%s|%s|p%d]', $p['source'], $unit, (int)($p['cursor']['page'] ?? 0));
    }

    /** 目标页上限：显式 target_pages 优先，否则用全局缺省 */
    public static function targetPages(array $p, int $fallback): int
    {
        $tp = (int)($p['target_pages'] ?? 0);
        return $tp > 0 ? $tp : $fallback;
    }
}
