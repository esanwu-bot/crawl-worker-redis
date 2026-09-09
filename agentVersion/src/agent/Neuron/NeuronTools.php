<?php
declare(strict_types=1);

namespace Cw\Agent\Neuron;

use Cw\Db;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Engine 只读能力 → Neuron v3 Tool 契约（设计文档 §4.2 / §6）。
 *
 * 设计铁律：LLM 永不直接写 Redis / MySQL / 发 HTTP。这里只暴露 Engine 的
 * 只读能力给 Agent 的 function calling 闭环；写操作一律由上层 Workflow 走
 * 确定性代码 + 人工审批。
 */
final class NeuronTools
{
    /**
     * list_series —— 检索 / 枚举站点真实系列目录（source_types，只读）。
     *
     * @return Tool[]
     */
    public static function forPlanner(Db $db, ?callable $onCall = null): array
    {
        $tool = Tool::make(
            'list_series',
            '检索站点产品系列目录（只读）。按关键词在真实目录中搜索；keyword 为空时返回目录前 top 条。'
            . '返回每条含 type_id / type_name / type_name_en，type_id 是系列唯一标识。'
        );
        $tool->addProperty(ToolProperty::make('keyword', PropertyType::STRING, '检索关键词，可空（如 肖特基 / MOSFET / 二极管）', false));
        $tool->addProperty(ToolProperty::make('top', PropertyType::INTEGER, '最多返回条数，默认 20', false));

        $tool->setCallable(static function ($keyword = null, $top = null) use ($db, $onCall): array {
            $keyword = trim((string)($keyword ?? ''));
            $top     = max(1, min(50, (int)($top ?? 20)));
            if ($onCall !== null) {
                $onCall('list_series', ['keyword' => $keyword, 'top' => $top]);
            }

            $rows = $keyword !== ''
                ? $db->searchTypes($keyword, $top)
                : $db->listTypes($top);

            return array_map(static fn (array $r): array => [
                'type_id'      => (int)$r['type_id'],
                'type_name'    => (string)$r['type_name'],
                'type_name_en' => (string)$r['type_name_en'],
            ], $rows);
        });

        return [$tool];
    }
}
