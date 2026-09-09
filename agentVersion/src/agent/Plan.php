<?php
declare(strict_types=1);

namespace Cw\Agent;

/**
 * Agent 生成的执行计划，可被持久化、审批、追踪。
 */
class Plan
{
    /**
     * @param int[] $typeIds
     */
    public function __construct(
        public array $typeIds = [],
        public int $targetPages = 3,
        public string $reason = '',
        public bool $requiresApproval = false,
        public array $metadata = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'type_ids'          => $this->typeIds,
            'target_pages'      => $this->targetPages,
            'reason'            => $this->reason,
            'requires_approval' => $this->requiresApproval,
            'metadata'          => $this->metadata,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            array_map('intval', (array)($a['type_ids'] ?? [])),
            (int)($a['target_pages'] ?? 3),
            (string)($a['reason'] ?? ''),
            (bool)($a['requires_approval'] ?? false),
            (array)($a['metadata'] ?? [])
        );
    }
}
