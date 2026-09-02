<?php

declare(strict_types=1);

namespace FreeGateway;

final class UpstreamRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $url,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
