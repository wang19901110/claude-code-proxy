<?php

declare(strict_types=1);

namespace FreeGateway;

final class ProviderConfig
{
    /** @param array<string, string> $values */
    private function __construct(
        public readonly string $provider,
        public readonly string $directory,
        private readonly array $values,
    ) {
    }

    public static function fromDirectory(string $provider, string $directory): self
    {
        $path = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . '.env';
        return new self($provider, $directory, is_file($path) ? self::loadEnv($path) : []);
    }

    public function get(string $key, string $default = ''): string
    {
        $environmentKey = strtoupper(str_replace('-', '_', $this->provider)) . '_' . $key;
        $environmentValue = getenv($environmentKey);
        if ($environmentValue !== false) {
            return trim((string) $environmentValue);
        }

        return $this->values[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = strtolower(trim($this->get($key)));
        return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default, int $min, int $max): int
    {
        $value = filter_var($this->get($key), FILTER_VALIDATE_INT);
        return max($min, min($max, $value === false ? $default : (int) $value));
    }

    /** @return list<string> */
    public function secrets(): array
    {
        $secrets = [];
        $keys = array_values(array_unique([...array_keys($this->values), 'API_KEY', 'TOKEN', 'SECRET']));
        foreach ($keys as $key) {
            if (!preg_match('/(?:KEY|TOKEN|SECRET|PASSWORD)/i', $key)) {
                continue;
            }
            $value = $this->get($key);
            if ($value !== '') {
                $secrets[] = $value;
            }
        }
        return array_values(array_unique($secrets));
    }

    /** @return array<string, string> */
    private static function loadEnv(string $path): array
    {
        $result = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            if ($key !== '') {
                $result[$key] = trim($value, " \t\n\r\0\x0B\"'");
            }
        }
        return $result;
    }
}
