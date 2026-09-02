<?php

declare(strict_types=1);

namespace FreeGateway\Provider;

use FreeGateway\CatalogResult;
use FreeGateway\Failure;
use FreeGateway\ModelDescriptor;
use FreeGateway\RequestHeaders;
use FreeGateway\UpstreamRequest;
use Workerman\Http\Client;

interface ProviderAdapter
{
    public function name(): string;

    public function configured(): bool;

    public function concurrency(): int;

    /** @param callable(CatalogResult): void $complete */
    public function discover(Client $client, callable $complete): void;

    public function prepare(
        string $operation,
        ModelDescriptor $model,
        object $payload,
        RequestHeaders $headers,
    ): UpstreamRequest;

    public function classify(int $status, string $body = '', mixed $error = null): Failure;
}
