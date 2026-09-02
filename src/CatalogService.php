<?php

declare(strict_types=1);

namespace FreeGateway;

use FreeGateway\Provider\ProviderAdapter;
use Workerman\Http\Client;

final class CatalogService
{
    /** @var array<string, ModelDescriptor> */
    private array $models = [];

    /** @var array<string, array{fetched_at:int,error:?string,count:int}> */
    private array $providerStatus = [];

    private bool $refreshing = false;
    private int $lastRefresh = 0;

    /** @param array<string, ProviderAdapter> $providers */
    public function __construct(
        private readonly array $providers,
        private readonly string $cachePath,
        private readonly int $maxAgeSeconds,
    ) {
        $this->load();
    }

    /** @return list<ModelDescriptor> */
    public function all(): array
    {
        $models = array_values(array_filter(
            $this->models,
            fn (ModelDescriptor $model): bool => time() - $model->catalogedAt <= $this->maxAgeSeconds,
        ));
        usort($models, static fn (ModelDescriptor $a, ModelDescriptor $b): int =>
            [$a->priority, $a->alias] <=> [$b->priority, $b->alias]);
        return $models;
    }

    public function find(string $alias): ?ModelDescriptor
    {
        $model = $this->models[$alias] ?? null;
        if (!$model instanceof ModelDescriptor || time() - $model->catalogedAt > $this->maxAgeSeconds) {
            return null;
        }
        return $model;
    }

    /** @return array<string, mixed> */
    public function discovery(int $limit = 1000): array
    {
        $models = $this->all();
        $rows = [];
        if ($models !== []) {
            $rows[] = [
                'id' => 'claude-free-auto',
                'type' => 'model',
                'display_name' => 'Auto · All Providers',
                'created_at' => '1970-01-01T00:00:00Z',
            ];
        }
        foreach ($models as $model) {
            $rows[] = [
                'id' => $model->alias,
                'type' => 'model',
                'display_name' => $model->displayName,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', $model->catalogedAt),
                'max_input_tokens' => $model->contextLength,
                'capabilities' => $model->capabilities,
            ];
        }
        $rows = array_slice($rows, 0, max(1, min(1000, $limit)));
        return [
            'data' => $rows,
            'first_id' => $rows[0]['id'] ?? null,
            'has_more' => false,
            'last_id' => $rows === [] ? null : $rows[array_key_last($rows)]['id'],
        ];
    }

    /** @param callable(): void|null $finished */
    public function refresh(Client $client, ?callable $finished = null): void
    {
        if ($this->refreshing) {
            if ($finished !== null) {
                $finished();
            }
            return;
        }
        $this->refreshing = true;
        $pending = count($this->providers);
        if ($pending === 0) {
            $this->refreshing = false;
            if ($finished !== null) {
                $finished();
            }
            return;
        }

        foreach ($this->providers as $name => $provider) {
            $provider->discover($client, function (CatalogResult $result) use (&$pending, $name, $finished): void {
                if ($result->error === null) {
                    foreach ($this->models as $alias => $model) {
                        if ($model->provider === $name) {
                            unset($this->models[$alias]);
                        }
                    }
                    foreach ($result->models as $model) {
                        $this->models[$model->alias] = $model;
                    }
                }
                $this->providerStatus[$name] = [
                    'fetched_at' => $result->fetchedAt,
                    'error' => $result->error,
                    'count' => count($result->models),
                ];
                $pending--;
                if ($pending === 0) {
                    $this->lastRefresh = time();
                    $this->refreshing = false;
                    $this->persist();
                    if ($finished !== null) {
                        $finished();
                    }
                }
            });
        }
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return [
            'refreshing' => $this->refreshing,
            'last_refresh' => $this->lastRefresh > 0 ? gmdate('c', $this->lastRefresh) : null,
            'model_count' => count($this->all()),
            'providers' => $this->providerStatus,
        ];
    }

    private function load(): void
    {
        if (!is_file($this->cachePath)) {
            return;
        }
        $payload = json_decode((string) file_get_contents($this->cachePath), true);
        if (!is_array($payload) || !is_array($payload['models'] ?? null)) {
            return;
        }
        foreach ($payload['models'] as $data) {
            if (!is_array($data)) {
                continue;
            }
            $model = ModelDescriptor::fromArray($data);
            if (Alias::isSpecific($model->alias) && $model->freeEvidence !== '') {
                $this->models[$model->alias] = $model;
            }
        }
        $this->providerStatus = is_array($payload['providers'] ?? null) ? $payload['providers'] : [];
        $this->lastRefresh = (int) ($payload['saved_at'] ?? 0);
    }

    private function persist(): void
    {
        $directory = dirname($this->cachePath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $payload = json_encode([
            'saved_at' => time(),
            'providers' => $this->providerStatus,
            'models' => array_map(static fn (ModelDescriptor $model): array => $model->toArray(), array_values($this->models)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        $temporary = $this->cachePath . '.tmp.' . getmypid();
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            return;
        }
        if (!@rename($temporary, $this->cachePath)) {
            @unlink($this->cachePath);
            @rename($temporary, $this->cachePath);
        }
    }
}
