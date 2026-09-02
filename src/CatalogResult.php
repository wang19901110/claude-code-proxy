<?php

declare(strict_types=1);

namespace FreeGateway;

final class CatalogResult
{
    /** @param list<ModelDescriptor> $models */
    public function __construct(
        public readonly string $provider,
        public readonly array $models,
        public readonly int $fetchedAt,
        public readonly ?string $error = null,
    ) {
    }
}
