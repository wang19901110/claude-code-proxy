<?php

declare(strict_types=1);

namespace FreeGateway;

use RuntimeException;

final class Config
{
    /** @param array<string, string> $env */
    private function __construct(
        public readonly string $root,
        public readonly string $host,
        public readonly int $port,
        public readonly bool $logEnabled,
        public readonly int $catalogRefreshSeconds = 900,
        public readonly int $catalogMaxAgeSeconds = 86400,
        public readonly int $maxBodyBytes = 33554432,
        public readonly int $queueCapacity = 64,
        public readonly int $queueMaxBytes = 67108864,
        public readonly int $maxRouteAttempts = 4,
        public readonly int $attemptTimeoutSeconds = 180,
        public readonly int $routeTimeoutSeconds = 240,
    ) {
    }

    public static function fromFile(string $path, string $root): self
    {
        $env = self::loadEnv($path);
        $host = trim($env['GATEWAY_HOST'] ?? '127.0.0.1');
        if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('GATEWAY_HOST must remain bound to localhost.');
        }

        return new self(
            root: rtrim($root, '\\/'),
            host: $host === 'localhost' ? '127.0.0.1' : $host,
            port: self::int($env, 'GATEWAY_PORT', 8787, 1, 65535),
            logEnabled: self::bool($env, 'LOG_ENABLED', true),
        );
    }

    /** @return array<string, string> */
    private static function loadEnv(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Configuration file not found: {$path}");
        }
        $result = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $result[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }
        return $result;
    }

    /** @param array<string, string> $env */
    private static function int(array $env, string $key, int $default, int $min, int $max): int
    {
        $value = filter_var($env[$key] ?? null, FILTER_VALIDATE_INT);
        return max($min, min($max, $value === false || $value === null ? $default : (int) $value));
    }

    /** @param array<string, string> $env */
    private static function bool(array $env, string $key, bool $default): bool
    {
        $value = strtolower(trim($env[$key] ?? ''));
        return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
