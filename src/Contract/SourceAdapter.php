<?php
declare(strict_types=1);

namespace Cw\Contract;

use Cw\Db;

/**
 * 数据源适配器接口（flag.md §11 首个里程碑）。
 *
 * Runtime（Worker / Producer / RedisStore）只依赖本接口与 Task 契约，
 * 对数据源的具体协议、字段、分页语义完全无感知 —— 增长只发生在 Adapter 层。
 *
 * 一个 Adapter 覆盖 flag.md §3 的链路并把它收敛在自己内部：
 *   Connector + Extractor + Normalizer → Canonical Record（交给 Runtime 的 Sink 落库）
 */
interface SourceAdapter
{
    /** 数据源标识，与 Task.source / crawl_jobs.source 一致 */
    public function source(): string;

    /**
     * 发现采集单元（播种阶段的“目录”）。
     * 实现方可把单元元数据写入 source_types（带 source 列的通用目录表）作领域沉淀，
     * 但无需返回除单元列表之外的任何结构。
     *
     * @return list<array{entity:string, unit_id:int|string, unit_name:string, params?:array}>
     */
    public function discoverUnits(Db $db): array;

    /**
     * 执行一次“列表页”任务。
     *
     * @param array $task 完整 Task 载荷（见 Cw\Contract\Task）
     * @return array{
     *   page: int,
     *   last_page: int,
     *   ended: bool,
     *   records: list<array>
     * }
     *   records 为 Canonical Record，字段规范见 flag.md §5：
     *   source/entity/unit_id/unit_name/external_id/title/url/image/
     *   payload(领域可检索字段)/raw(原始行)/source_url/page。
     *
     * @throws \RuntimeException 网络/解析/协议异常由任务层按既有重试/死信语义处理
     */
    public function executeList(array $task): array;
}
