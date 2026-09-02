<?php

declare(strict_types=1);

namespace FreeGateway;

final class CurlUpstreamResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body,
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaderLine(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
