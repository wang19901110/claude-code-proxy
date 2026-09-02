<?php

declare(strict_types=1);

namespace FreeGateway;

final class ModelDescriptor
{
    /** @param list<string> $capabilities @param list<string> $inputModalities */
    public function __construct(
        public readonly string $provider,
        public readonly string $upstreamId,
        public readonly string $alias,
        public readonly string $displayName,
        public readonly array $capabilities,
        public readonly array $inputModalities,
        public readonly int $contextLength,
        public readonly int $priority,
        public readonly string $freeEvidence,
        public readonly int $catalogedAt,
        public readonly bool $virtual = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            provider: (string) ($data['provider'] ?? ''),
            upstreamId: (string) ($data['upstreamId'] ?? ''),
            alias: (string) ($data['alias'] ?? ''),
            displayName: (string) ($data['displayName'] ?? ''),
            capabilities: array_values(array_filter($data['capabilities'] ?? [], 'is_string')),
            inputModalities: array_values(array_filter($data['inputModalities'] ?? ['text'], 'is_string')),
            contextLength: (int) ($data['contextLength'] ?? 0),
            priority: (int) ($data['priority'] ?? 100),
            freeEvidence: (string) ($data['freeEvidence'] ?? ''),
            catalogedAt: (int) ($data['catalogedAt'] ?? 0),
            virtual: (bool) ($data['virtual'] ?? false),
        );
    }
}
