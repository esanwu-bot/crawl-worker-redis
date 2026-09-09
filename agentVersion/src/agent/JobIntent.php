<?php
declare(strict_types=1);

namespace Cw\Agent;

/**
 * 用户意图的 DTO。
 * 自然语言 / JSON 都会先归一化成这个对象，再交给 Planner。
 */
class JobIntent
{
    public function __construct(
        public string $id,
        public string $text,
        public array $extra = []
    ) {
    }

    public static function fromPayload(array $payload): self
    {
        $id   = (string)($payload['id'] ?? self::generateId());
        $text = trim((string)($payload['intent'] ?? $payload['text'] ?? ''));
        return new self($id, $text, $payload['extra'] ?? []);
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function toArray(): array
    {
        return [
            'id'    => $this->id,
            'text'  => $this->text,
            'extra' => $this->extra,
        ];
    }
}
