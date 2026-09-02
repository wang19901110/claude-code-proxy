<?php

declare(strict_types=1);

namespace FreeGateway;

use RuntimeException;

final class ModelAliasRegistry
{
    /** @var array<string, int> */
    private array $models = [];

    private int $next = 1;

    public function __construct(
        public readonly string $provider,
        public readonly int $platformIndex,
        private readonly string $cachePath,
    ) {
        if ($platformIndex < 1) {
            throw new RuntimeException("Provider {$provider} platform index must be positive.");
        }
        $this->load();
    }

    public function for(string $upstreamId): string
    {
        $upstreamId = trim($upstreamId);
        if ($upstreamId === '') {
            throw new RuntimeException("Provider {$this->provider} cannot allocate an alias for an empty model ID.");
        }
        if (!isset($this->models[$upstreamId])) {
            $this->models[$upstreamId] = $this->next++;
            $this->persist();
        }
        return Alias::for($this->platformIndex, $this->models[$upstreamId]);
    }

    private function load(): void
    {
        if (!is_file($this->cachePath)) {
            return;
        }
        $payload = json_decode((string) file_get_contents($this->cachePath), true);
        if (!is_array($payload) || ($payload['provider'] ?? null) !== $this->provider) {
            throw new RuntimeException("Invalid alias registry for provider {$this->provider}.");
        }
        $models = $payload['models'] ?? null;
        if (!is_array($models)) {
            throw new RuntimeException("Invalid alias model map for provider {$this->provider}.");
        }
        $used = [];
        foreach ($models as $upstreamId => $index) {
            if (!is_string($upstreamId) || $upstreamId === '' || !is_int($index) || $index < 1 || isset($used[$index])) {
                throw new RuntimeException("Invalid alias assignment for provider {$this->provider}.");
            }
            $this->models[$upstreamId] = $index;
            $used[$index] = true;
            $this->next = max($this->next, $index + 1);
        }
    }

    private function persist(): void
    {
        $directory = dirname($this->cachePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create alias registry directory: {$directory}");
        }
        $payload = json_encode([
            'provider' => $this->provider,
            'platform_index' => $this->platformIndex,
            'models' => $this->models,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $this->cachePath . '.tmp.' . getmypid();
        if (file_put_contents($temporary, $payload . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write alias registry for provider {$this->provider}.");
        }
        if (!@rename($temporary, $this->cachePath)) {
            @unlink($this->cachePath);
            if (!@rename($temporary, $this->cachePath)) {
                @unlink($temporary);
                throw new RuntimeException("Unable to replace alias registry for provider {$this->provider}.");
            }
        }
    }
}
