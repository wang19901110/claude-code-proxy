<?php

declare(strict_types=1);

namespace FreeGateway\Providers\Bai;

use FreeGateway\CatalogResult;
use FreeGateway\ModelAliasRegistry;
use FreeGateway\ModelDescriptor;
use FreeGateway\Provider\AbstractAnthropicProvider;
use FreeGateway\RequestHeaders;
use FreeGateway\SecretGuard;
use FreeGateway\UpstreamRequest;
use Workerman\Http\Client;

final class Provider extends AbstractAnthropicProvider
{
    private const FREE_MODELS = [
        'glm-5.3-flash' => ['priority' => 10, 'context' => 131072],
        'deepseek-v4-flash' => ['priority' => 11, 'context' => 32000],
        'qwen3.8-flash' => ['priority' => 12, 'context' => 64000],
        'hy3' => ['priority' => 13, 'context' => 32000],
        'mimo-v2.5' => ['priority' => 14, 'context' => 131072],
    ];

    public function __construct(
        string $apiKey,
        string $baseUrl,
        SecretGuard $secrets,
        private readonly ModelAliasRegistry $aliases,
        private readonly int $limit,
    ) {
        parent::__construct($apiKey, $baseUrl, $secrets);
    }

    public function name(): string
    {
        return 'bai';
    }

    public function concurrency(): int
    {
        return $this->limit;
    }

    public function prepare(
        string $operation,
        ModelDescriptor $model,
        object $payload,
        RequestHeaders $headers,
    ): UpstreamRequest {
        $copy = clone $payload;
        if (isset($copy->max_tokens) && is_numeric($copy->max_tokens) && (int) $copy->max_tokens <= 2) {
            // Claude Code uses tiny probe values; B.AI rejects values below 3.
            $copy->max_tokens = 3;
        }
        return parent::prepare($operation, $model, $copy, $headers);
    }

    public function discover(Client $client, callable $complete): void
    {
        if (!$this->configured()) {
            $complete(new CatalogResult($this->name(), [], time(), 'API key is not configured.'));
            return;
        }

        $client->request(rtrim($this->baseUrl, '/') . '/models', [
            'method' => 'GET',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
                'Connection' => 'close',
            ],
            'success' => function ($response) use ($complete): void {
                $status = $response->getStatusCode();
                $payload = json_decode((string) $response->getBody());
                if ($status >= 300 || !$payload instanceof \stdClass || !is_array($payload->data ?? null)) {
                    $complete(new CatalogResult($this->name(), [], time(), "Catalog request failed with HTTP {$status}."));
                    return;
                }
                $available = [];
                foreach ($payload->data as $entry) {
                    if ($entry instanceof \stdClass && is_string($entry->id ?? null)) {
                        $available[$entry->id] = true;
                    }
                }
                $now = time();
                $models = [];
                foreach (self::FREE_MODELS as $id => $definition) {
                    if (!isset($available[$id])) {
                        continue;
                    }
                    $models[] = new ModelDescriptor(
                        provider: $this->name(),
                        upstreamId: $id,
                        alias: $this->aliases->for($id),
                        displayName: "B.AI · {$id} · Free",
                        capabilities: ['tools', 'tool_choice', 'thinking', 'prompt_caching', 'system_blocks'],
                        inputModalities: ['text'],
                        contextLength: $definition['context'],
                        priority: $definition['priority'],
                        freeEvidence: 'bai-static-allowlist-v1+credential-catalog',
                        catalogedAt: $now,
                    );
                }
                $complete(new CatalogResult($this->name(), $models, $now));
            },
            'error' => fn ($error) => $complete(new CatalogResult($this->name(), [], time(), 'Catalog connection failed.')),
        ]);
    }
}
