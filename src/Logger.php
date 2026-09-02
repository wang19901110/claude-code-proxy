<?php

declare(strict_types=1);

namespace FreeGateway;

final class Logger
{
    public function __construct(private readonly bool $enabled, private readonly string $directory) {}

    /** @param array<string, mixed> $context */
    public function event(string $name, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0700, true);
        }
        $allowed = array_intersect_key($context, array_flip([
            'request_id', 'provider', 'model', 'status', 'duration_ms', 'failure', 'attempt', 'stream',
        ]));
        $line = json_encode(['time' => gmdate('c'), 'event' => $name] + $allowed, JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            @file_put_contents($this->directory . '/' . gmdate('Y-m-d') . '.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
