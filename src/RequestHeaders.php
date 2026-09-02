<?php

declare(strict_types=1);

namespace FreeGateway;

final class RequestHeaders
{
    /** @param array<string, string> $headers */
    public function __construct(public readonly array $headers) {}

    public function get(string $name, string $default = ''): string
    {
        $name = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }
        return $default;
    }

    /** @return array<string, string> */
    public function anthropic(): array
    {
        $result = [];
        foreach ($this->headers as $key => $value) {
            if (str_starts_with(strtolower($key), 'anthropic-')) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
