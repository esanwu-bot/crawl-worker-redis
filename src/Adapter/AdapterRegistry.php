<?php
declare(strict_types=1);

namespace Cw\Adapter;

use Cw\Contract\SourceAdapter;
use RuntimeException;

/**
 * Adapter 路由注册表：Task 载荷里的 source 决定用哪个 Adapter。
 * Worker / Producer 通过本注册表取适配器，是 Runtime 保持“零领域分支”的唯一入口。
 */
class AdapterRegistry
{
    /** @var array<string, SourceAdapter> */
    private array $adapters = [];

    /** @param iterable<string, SourceAdapter> $adapters source => adapter */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $source => $adapter) {
            $this->register($adapter, (string)$source);
        }
    }

    public function register(SourceAdapter $adapter, ?string $source = null): void
    {
        $this->adapters[$source ?: $adapter->source()] = $adapter;
    }

    public function has(string $source): bool
    {
        return isset($this->adapters[$source]);
    }

    public function get(string $source): SourceAdapter
    {
        if (!isset($this->adapters[$source])) {
            throw new RuntimeException('未注册的数据源: ' . $source);
        }
        return $this->adapters[$source];
    }

    /** @return list<string> */
    public function sources(): array
    {
        return array_keys($this->adapters);
    }

    /** @return array<string, SourceAdapter> */
    public function all(): array
    {
        return $this->adapters;
    }
}
