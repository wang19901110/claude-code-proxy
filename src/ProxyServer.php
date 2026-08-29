<?php

declare(strict_types=1);

namespace ClaudeCodeProxy;

use RuntimeException;
use Throwable;
use Workerman\Http\Client;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

final class ProxyServer
{
    /** @var array<string, Client> */
    private array $clients = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ProviderRegistry $registry,
        private readonly ProxyLogger $logger,
    ) {
    }

    public function run(): void
    {
        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 8787);
        if (!in_array($host, ['127.0.0.1', '::1'], true)) {
            throw new RuntimeException('For safety, the proxy host must be 127.0.0.1 or ::1.');
        }
        if (!$this->registry->hasConfiguredProviders()) {
            throw new RuntimeException(
                'No configured provider is available. ' . implode(' ', $this->registry->configurationHints()),
            );
        }

        $worker = new Worker(sprintf('http://%s:%d', $host, $port));
        $worker->name = 'claude-code-proxy';
        $worker->count = 1;
        $worker->onWorkerStart = function (): void {
            foreach ($this->registry->configuredProviders() as $provider) {
                $this->clients[$provider->id()] = new Client([
                    'connect_timeout' => max(1, (int) ($this->config['connect_timeout'] ?? 30)),
                    'timeout' => $provider->requestTimeout((int) ($this->config['upstream_timeout'] ?? 180)),
                    'keepalive_timeout' => max(1, (int) ($this->config['keepalive_timeout'] ?? 30)),
                ]);
            }
        };
        $worker->onMessage = function ($connection, Request $request): void {
            $this->handle($connection, $request);
        };

        $this->logger->reset();
        foreach ($this->registry->providers() as $provider) {
            if (!$provider->isConfigured()) {
                $this->logger->log('warning', 'provider_disabled', [
                    'provider' => $provider->id(),
                    'hint' => $provider->configurationHint(),
                ]);
            }
        }

        fwrite(STDOUT, sprintf("Claude Code Desktop proxy listening on http://%s:%d\n", $host, $port));
        $this->logger->log('info', 'proxy_started', [
            'host' => $host,
            'port' => $port,
            'provider_count' => count($this->registry->configuredProviders()),
            'model_count' => count($this->registry->discoveryModels()),
        ]);
        Worker::runAll();
    }

    private function handle($connection, Request $request): void
    {
        $path = $request->path();
        $method = strtoupper($request->method());
        $requestId = bin2hex(random_bytes(8));
        $startedAt = microtime(true);

        $this->logger->log('info', 'request_received', [
            'request_id' => $requestId,
            'method' => $method,
            'path' => $path,
        ]);

        if ($method === 'OPTIONS') {
            $connection->send(new Response(204, [
                'Access-Control-Allow-Origin' => 'http://127.0.0.1',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Api-Key, Anthropic-Version, Anthropic-Beta',
                'Access-Control-Allow-Methods' => 'GET, POST, HEAD, OPTIONS',
            ]));
            $this->logCompletion($requestId, 204, $startedAt);
            return;
        }

        if ($method === 'GET' && ($path === '/' || $path === '/health')) {
            $connection->send($this->jsonResponse(200, [
                'status' => 'ok',
                'service' => 'claude-code-proxy',
                'providers' => $this->registry->health(),
                'model_count' => count($this->registry->discoveryModels()),
            ]));
            $this->logCompletion($requestId, 200, $startedAt);
            return;
        }

        if (in_array($method, ['GET', 'POST', 'HEAD'], true) && $path === '/api/hello') {
            $connection->send($method === 'HEAD'
                ? new Response(200, ['Content-Type' => 'application/json; charset=utf-8'])
                : $this->jsonResponse(200, [
                    'status' => 'ok',
                    'service' => 'claude-code-proxy',
                    'provider_count' => count($this->registry->configuredProviders()),
                    'model_count' => count($this->registry->discoveryModels()),
                ]));
            $this->logCompletion($requestId, 200, $startedAt, ['endpoint' => 'api_hello']);
            return;
        }

        if ($method === 'GET' && $path === '/v1/models') {
            $models = [];
            foreach ($this->registry->discoveryModels() as $model) {
                $models[] = [
                    'id' => $model['alias'],
                    'object' => 'model',
                    'owned_by' => $model['provider_id'],
                    'display_name' => $model['display_name'] ?? $model['upstream_model'],
                    'upstream_model' => $model['upstream_model'],
                    'provider' => $model['provider_id'],
                ];
            }
            $connection->send($this->jsonResponse(200, ['object' => 'list', 'data' => $models]));
            $this->logCompletion($requestId, 200, $startedAt, ['model_count' => count($models)]);
            return;
        }

        if ($method !== 'POST' || !in_array($path, ['/v1/messages', '/v1/messages/count_tokens'], true)) {
            $connection->send($this->errorResponse(
                404,
                'not_found_error',
                'Only /v1/messages, /v1/messages/count_tokens, /v1/models, /health, and /api/hello are available.',
            ));
            $this->logCompletion($requestId, 404, $startedAt);
            return;
        }

        $payload = json_decode($request->rawBody(), true);
        if (!is_array($payload)) {
            $connection->send($this->errorResponse(400, 'invalid_request_error', 'The request body must be valid JSON.'));
            $this->logCompletion($requestId, 400, $startedAt, ['error_type' => 'invalid_json']);
            return;
        }

        if ($path === '/v1/messages/count_tokens') {
            $connection->send($this->jsonResponse(200, ['input_tokens' => $this->countTokensLocally($payload)]));
            $this->logCompletion($requestId, 200, $startedAt, ['endpoint' => 'count_tokens']);
            return;
        }

        $requestedModel = (string) ($payload['model'] ?? '');
        if ($requestedModel === '') {
            $connection->send($this->errorResponse(400, 'invalid_request_error', 'model is required.'));
            $this->logCompletion($requestId, 400, $startedAt, ['error_type' => 'missing_model']);
            return;
        }

        $route = $this->registry->routeFor($requestedModel);
        if ($route === null) {
            $connection->send($this->errorResponse(
                400,
                'invalid_request_error',
                'This model alias is not mapped by an enabled provider.',
            ));
            $this->logCompletion($requestId, 400, $startedAt, [
                'requested_model' => $requestedModel,
                'error_type' => 'unmapped_model',
            ]);
            return;
        }

        $provider = $route['provider'];
        $model = $route['model'];
        try {
            $upstream = $provider->prepareRequest($payload, $model, $request);
        } catch (Throwable $exception) {
            $connection->send($this->errorResponse(400, 'invalid_request_error', $exception->getMessage()));
            $this->logCompletion($requestId, 400, $startedAt, [
                'provider' => $provider->id(),
                'requested_model' => $requestedModel,
                'error_type' => 'provider_request_error',
            ]);
            return;
        }

        $stream = (bool) $upstream['stream'];
        $upstreamModel = (string) $model['upstream_model'];
        $this->logger->log('info', 'forwarding_request', [
            'request_id' => $requestId,
            'provider' => $provider->id(),
            'requested_model' => $requestedModel,
            'upstream_model' => $upstreamModel,
            'stream' => $stream,
            'requested_max_tokens' => $upstream['requested_max_tokens'],
            'max_tokens' => $upstream['max_tokens'],
        ]);

        $client = $this->clients[$provider->id()] ?? null;
        if (!$client instanceof Client) {
            $connection->send($this->errorResponse(500, 'api_error', 'The selected provider client is unavailable.'));
            $this->logCompletion($requestId, 500, $startedAt, ['error_type' => 'missing_provider_client']);
            return;
        }

        $adapter = $provider->responseAdapter($model, $route['client_alias']);
        if ($stream) {
            $this->forwardStream(
                $client,
                $connection,
                $upstream,
                $adapter,
                $provider->id(),
                $requestId,
                $requestedModel,
                $upstreamModel,
                $startedAt,
            );
            return;
        }

        $this->forwardJson(
            $client,
            $connection,
            $upstream,
            $adapter,
            $provider->id(),
            $requestId,
            $requestedModel,
            $upstreamModel,
            $startedAt,
        );
    }

    /** @param array<string, mixed> $upstream */
    private function forwardStream(
        Client $client,
        $connection,
        array $upstream,
        AnthropicResponseAdapter $adapter,
        string $providerId,
        string $requestId,
        string $requestedModel,
        string $upstreamModel,
        float $startedAt,
    ): void {
        $connection->send(new Response(200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'Transfer-Encoding' => 'chunked',
            'X-Accel-Buffering' => 'no',
        ], ''));

        $pending = '';
        $streamBytes = 0;
        $streamChunks = 0;
        $sseState = $adapter->initialSseState();

        try {
            $client->request((string) $upstream['endpoint'], [
                'method' => 'POST',
                'headers' => $upstream['headers'],
                'data' => $upstream['body'],
                'progress' => static function (string $buffer) use (
                    $connection,
                    $adapter,
                    &$pending,
                    &$streamBytes,
                    &$streamChunks,
                    &$sseState,
                ): void {
                    $streamBytes += strlen($buffer);
                    $streamChunks++;
                    $rewritten = $adapter->rewriteSseBuffer($pending, $buffer, $sseState);
                    if ($rewritten !== '') {
                        $connection->send(new Chunk($rewritten));
                    }
                },
                'success' => function ($response) use (
                    $connection,
                    $adapter,
                    $providerId,
                    $requestId,
                    $requestedModel,
                    $upstreamModel,
                    $startedAt,
                    &$pending,
                    &$streamBytes,
                    &$streamChunks,
                    &$sseState,
                ): void {
                    $status = $response->getStatusCode();
                    if ($status >= 400 || ($streamBytes === 0 && $pending === '')) {
                        $message = $status >= 400
                            ? 'Upstream returned HTTP ' . $status . '.'
                            : 'Upstream returned an empty stream.';
                        $connection->send(new Chunk(self::errorEvent($message)));
                        $connection->send(new Chunk(''));
                        $this->logger->log($status >= 400 ? 'warning' : 'error', 'upstream_response', [
                            'request_id' => $requestId,
                            'provider' => $providerId,
                            'requested_model' => $requestedModel,
                            'upstream_model' => $upstreamModel,
                            'stream' => true,
                            'status' => $status,
                            'stream_bytes' => $streamBytes,
                            'stream_chunks' => $streamChunks,
                            'error_type' => $status >= 400 ? 'upstream_http_error' : 'empty_stream',
                            'latency_ms' => self::latencyMs($startedAt),
                        ]);
                        return;
                    }

                    $tail = $adapter->flushPendingSse($pending, $sseState);
                    if ($tail !== '') {
                        $connection->send(new Chunk($tail));
                    }
                    $repaired = $adapter->finishSse($sseState);
                    if ($repaired !== '') {
                        $connection->send(new Chunk($repaired));
                    }
                    $connection->send(new Chunk(''));
                    $this->logger->log('info', 'upstream_response', [
                        'request_id' => $requestId,
                        'provider' => $providerId,
                        'requested_model' => $requestedModel,
                        'upstream_model' => $upstreamModel,
                        'stream' => true,
                        'status' => $status,
                        'stream_bytes' => $streamBytes,
                        'stream_chunks' => $streamChunks,
                        'sse_repaired' => $repaired !== '',
                        'latency_ms' => self::latencyMs($startedAt),
                    ]);
                },
                'error' => function (Throwable $exception) use (
                    $connection,
                    $providerId,
                    $requestId,
                    $requestedModel,
                    $upstreamModel,
                    $startedAt,
                    &$streamBytes,
                    &$streamChunks,
                ): void {
                    $connection->send(new Chunk(self::errorEvent('Upstream connection failed.')));
                    $connection->send(new Chunk(''));
                    $this->logger->log('error', 'upstream_error', [
                        'request_id' => $requestId,
                        'provider' => $providerId,
                        'requested_model' => $requestedModel,
                        'upstream_model' => $upstreamModel,
                        'stream' => true,
                        'stream_bytes' => $streamBytes,
                        'stream_chunks' => $streamChunks,
                        'error' => $exception->getMessage(),
                        'latency_ms' => self::latencyMs($startedAt),
                    ]);
                },
            ]);
        } catch (Throwable $exception) {
            $connection->send(new Chunk(self::errorEvent('Upstream connection failed.')));
            $connection->send(new Chunk(''));
            $this->logger->log('error', 'upstream_error', [
                'request_id' => $requestId,
                'provider' => $providerId,
                'requested_model' => $requestedModel,
                'upstream_model' => $upstreamModel,
                'stream' => true,
                'error' => $exception->getMessage(),
                'latency_ms' => self::latencyMs($startedAt),
            ]);
        }
    }

    /** @param array<string, mixed> $upstream */
    private function forwardJson(
        Client $client,
        $connection,
        array $upstream,
        AnthropicResponseAdapter $adapter,
        string $providerId,
        string $requestId,
        string $requestedModel,
        string $upstreamModel,
        float $startedAt,
    ): void {
        try {
            $client->request((string) $upstream['endpoint'], [
                'method' => 'POST',
                'headers' => $upstream['headers'],
                'data' => $upstream['body'],
                'success' => function ($response) use (
                    $connection,
                    $adapter,
                    $providerId,
                    $requestId,
                    $requestedModel,
                    $upstreamModel,
                    $startedAt,
                ): void {
                    $status = $response->getStatusCode();
                    $body = (string) $response->getBody();
                    $contentType = $response->getHeaderLine('Content-Type') ?: 'application/json; charset=utf-8';
                    $connection->send(new Response(
                        $status,
                        ['Content-Type' => $contentType],
                        $adapter->rewriteJson($body),
                    ));
                    $this->logger->log($status >= 400 ? 'warning' : 'info', 'upstream_response', [
                        'request_id' => $requestId,
                        'provider' => $providerId,
                        'requested_model' => $requestedModel,
                        'upstream_model' => $upstreamModel,
                        'stream' => false,
                        'status' => $status,
                        'response_bytes' => strlen($body),
                        'latency_ms' => self::latencyMs($startedAt),
                    ]);
                },
                'error' => function (Throwable $exception) use (
                    $connection,
                    $providerId,
                    $requestId,
                    $requestedModel,
                    $upstreamModel,
                    $startedAt,
                ): void {
                    $connection->send($this->errorResponse(502, 'api_error', 'Upstream connection failed.'));
                    $this->logger->log('error', 'upstream_error', [
                        'request_id' => $requestId,
                        'provider' => $providerId,
                        'requested_model' => $requestedModel,
                        'upstream_model' => $upstreamModel,
                        'stream' => false,
                        'error' => $exception->getMessage(),
                        'latency_ms' => self::latencyMs($startedAt),
                    ]);
                },
            ]);
        } catch (Throwable $exception) {
            $connection->send($this->errorResponse(502, 'api_error', 'Upstream connection failed.'));
            $this->logger->log('error', 'upstream_error', [
                'request_id' => $requestId,
                'provider' => $providerId,
                'requested_model' => $requestedModel,
                'upstream_model' => $upstreamModel,
                'stream' => false,
                'error' => $exception->getMessage(),
                'latency_ms' => self::latencyMs($startedAt),
            ]);
        }
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function errorResponse(int $status, string $type, string $message): Response
    {
        return $this->jsonResponse($status, [
            'type' => 'error',
            'error' => ['type' => $type, 'message' => $message],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function countTokensLocally(array $payload): int
    {
        $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $characters = function_exists('mb_strlen') ? mb_strlen($serialized, 'UTF-8') : strlen($serialized);
        return max(1, (int) ceil($characters / 4));
    }

    /** @param array<string, mixed> $extra */
    private function logCompletion(string $requestId, int $status, float $startedAt, array $extra = []): void
    {
        $this->logger->log($status >= 400 ? 'warning' : 'info', 'request_completed', [
            'request_id' => $requestId,
            'status' => $status,
            'latency_ms' => self::latencyMs($startedAt),
        ] + $extra);
    }

    private static function latencyMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private static function errorEvent(string $message): string
    {
        return 'event: error' . "\n" . 'data: ' . json_encode([
            'type' => 'error',
            'error' => ['type' => 'api_error', 'message' => $message],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }
}
