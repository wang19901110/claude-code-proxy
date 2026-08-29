<?php

declare(strict_types=1);

namespace ClaudeCodeProxy;

use Workerman\Protocols\Http\Request;

interface ProviderInterface
{
    public function id(): string;

    public function displayName(): string;

    public function isConfigured(): bool;

    public function configurationHint(): string;

    public function requestTimeout(int $default): int;

    /**
     * @return list<array{
     *   alias: string,
     *   upstream_model: string,
     *   max_tokens: int,
     *   display_name?: string,
     *   legacy_aliases?: list<string>,
     *   repair_incomplete_sse?: bool
     * }>
     */
    public function models(): array;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $model
     * @return array{
     *   endpoint: string,
     *   headers: array<string, string>,
     *   body: string,
     *   stream: bool,
     *   requested_max_tokens: int,
     *   max_tokens: int
     * }
     */
    public function prepareRequest(array $payload, array $model, Request $request): array;

    /** @param array<string, mixed> $model */
    public function responseAdapter(array $model, string $clientModel): ResponseAdapterInterface;
}
