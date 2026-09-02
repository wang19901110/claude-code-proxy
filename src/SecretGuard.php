<?php

declare(strict_types=1);

namespace FreeGateway;

final class SecretGuard
{
    /** @param list<string> $secrets */
    public function __construct(private readonly array $secrets) {}

    public function redactString(string $value): string
    {
        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $value = str_replace($secret, '[REDACTED_SECRET]', $value);
            }
        }
        return $value;
    }

    public function redactValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->redactValue($item);
            }
            return $value;
        }
        if ($value instanceof \stdClass) {
            foreach ($value as $key => $item) {
                $value->{$key} = $this->redactValue($item);
            }
        }
        return $value;
    }
}
