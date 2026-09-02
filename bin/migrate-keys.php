<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$rootEnv = $root . DIRECTORY_SEPARATOR . '.env';
$keysFile = $root . DIRECTORY_SEPARATOR . 'keys.txt';

/** @return array<string, string> */
function readEnv(string $path): array
{
    $values = [];
    foreach (is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $values[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
    return $values;
}

/** @param array<string, string> $values */
function writeCanonicalEnv(string $path, array $values): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create configuration directory: {$directory}");
    }
    $contents = '';
    foreach ($values as $key => $value) {
        $contents .= $key . '=' . $value . PHP_EOL;
    }
    if (is_file($path) && file_get_contents($path) === $contents) {
        return;
    }
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write configuration file: {$path}");
    }
}

/** @return array<string, string> */
function readLegacyKeys(string $path): array
{
    $lines = array_values(array_filter(
        array_map('trim', is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : []),
        static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
    ));
    $values = [];
    $pending = null;
    foreach ($lines as $line) {
        $normalized = strtolower(rtrim($line, ':：'));
        if ($normalized === 'b.ai' || $normalized === 'bai' || str_contains($normalized, 'b.ai')) {
            $pending = 'BAI_API_KEY';
            continue;
        }
        if ($pending !== null) {
            $values[$pending] = $line;
            $pending = null;
        }
    }
    return $values;
}

try {
    $rootValues = readEnv($rootEnv);
    $legacyKeys = readLegacyKeys($keysFile);

    $baiPath = $root . DIRECTORY_SEPARATOR . 'providers' . DIRECTORY_SEPARATOR . 'bai' . DIRECTORY_SEPARATOR . '.env';
    $bai = readEnv($baiPath);
    writeCanonicalEnv($baiPath, [
        'API_KEY' => $bai['API_KEY'] ?? $rootValues['BAI_API_KEY'] ?? $legacyKeys['BAI_API_KEY'] ?? '',
        'BASE_URL' => $bai['BASE_URL'] ?? 'https://api.b.ai/v1',
        'CONCURRENCY' => $bai['CONCURRENCY'] ?? '2',
    ]);

    foreach ([
        'BAI_API_KEY',
    ] as $providerSetting) {
        unset($rootValues[$providerSetting]);
    }
    writeCanonicalEnv($rootEnv, [
        'GATEWAY_HOST' => $rootValues['GATEWAY_HOST'] ?? '127.0.0.1',
        'GATEWAY_PORT' => $rootValues['GATEWAY_PORT'] ?? '8787',
        'LOG_ENABLED' => $rootValues['LOG_ENABLED'] ?? 'true',
    ] + $rootValues);

    fwrite(STDOUT, "Provider-local configuration is ready; keys.txt was left unchanged.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
