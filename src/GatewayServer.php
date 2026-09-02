<?php

declare(strict_types=1);

namespace FreeGateway;

use FreeGateway\Provider\ProviderAdapter;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Http\Client;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

final class GatewayServer
{
    private Client $client;
    private CatalogService $catalog;
    private Router $router;
    private ProviderLimiter $limiter;
    private Logger $logger;
    private SecretGuard $secrets;
    private CurlTransport $transport;

    /** @var array<string, ProviderAdapter> */
    private array $providers;

    /** @var array<int, MessageSession> */
    private array $sessions = [];

    private int $nextRequestId = 0;
    private array $counters = [
        'requests' => 0, 'completed' => 0, 'failed' => 0, 'fallbacks' => 0,
        'cancelled' => 0, 'queue_rejected' => 0,
    ];

    public function __construct(private readonly Config $config)
    {
        $registry = new ProviderRegistry($config->root . '/providers');
        $this->providers = $registry->load();
        $this->secrets = $registry->guard();
        $this->catalog = new CatalogService(
            $this->providers,
            $config->root . '/runtime/catalog.json',
            $config->catalogMaxAgeSeconds,
        );
        $this->router = new Router($this->catalog);
        $this->limiter = new ProviderLimiter(
            array_map(static fn (ProviderAdapter $provider): int => $provider->concurrency(), $this->providers),
            $config->queueCapacity,
            $config->queueMaxBytes,
        );
        $this->logger = new Logger($config->logEnabled, $config->root . '/log');
    }

    public function run(): void
    {
        $worker = new Worker(sprintf('http://%s:%d', $this->config->host, $this->config->port));
        $worker->name = 'claude-free-gateway';
        $worker->count = 1;
        $worker->onWorkerStart = function (): void {
            $this->client = $this->newHttpClient();
            $this->transport = new CurlTransport(
                10,
                $this->config->attemptTimeoutSeconds,
                $this->config->root . '/certificates/cacert.pem',
            );
            $this->catalog->refresh($this->client);
            Timer::add($this->config->catalogRefreshSeconds, fn () => $this->catalog->refresh($this->client));
        };
        $worker->onMessage = fn ($connection, Request $request) => $this->handle($connection, $request);

        fwrite(STDOUT, sprintf(
            "Claude Free Gateway listening on http://%s:%d\n",
            $this->config->host,
            $this->config->port,
        ));
        Worker::runAll();
    }

    private function handle(mixed $connection, Request $request): void
    {
        $method = strtoupper($request->method());
        $path = $request->path();

        if ($method === 'OPTIONS') {
            $connection->send(new Response(204, [
                'Access-Control-Allow-Origin' => 'http://127.0.0.1',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Api-Key, Anthropic-Version, Anthropic-Beta',
                'Access-Control-Allow-Methods' => 'GET, POST, HEAD, OPTIONS',
            ]));
            return;
        }
        if (in_array($method, ['HEAD', 'GET'], true) && $path === '/api/hello') {
            $connection->send($method === 'HEAD'
                ? new Response(200, ['Content-Type' => 'application/json; charset=utf-8'])
                : AnthropicProtocol::jsonResponse(200, ['status' => 'ok', 'service' => 'claude-free-gateway']));
            return;
        }
        if ($method === 'GET' && in_array($path, ['/', '/health'], true)) {
            $connection->send(AnthropicProtocol::jsonResponse(200, $this->health()));
            return;
        }
        if ($method === 'GET' && $path === '/v1/models') {
            $limit = (int) $request->get('limit', 1000);
            $connection->send(AnthropicProtocol::jsonResponse(200, $this->catalog->discovery($limit)));
            return;
        }
        if ($method === 'POST' && $path === '/v1/messages/count_tokens') {
            $this->countTokens($connection, $request);
            return;
        }
        if ($method === 'POST' && $path === '/v1/messages') {
            $this->messages($connection, $request);
            return;
        }
        $connection->send(AnthropicProtocol::errorResponse(404, 'not_found_error', 'Endpoint not found.'));
    }

    private function countTokens(mixed $connection, Request $request): void
    {
        $payload = $this->payload($connection, $request);
        if (!$payload instanceof \stdClass) {
            return;
        }
        $requested = trim((string) ($payload->model ?? ''));
        if ($requested !== 'claude-free-auto' && $this->catalog->find($requested) === null) {
            $connection->send(AnthropicProtocol::errorResponse(400, 'invalid_request_error', 'Unknown or unavailable free model.'));
            return;
        }
        $connection->send(AnthropicProtocol::jsonResponse(200, ['input_tokens' => AnthropicProtocol::estimateTokens($payload)]));
    }

    private function messages(mixed $connection, Request $request): void
    {
        $payload = $this->payload($connection, $request);
        if (!$payload instanceof \stdClass) {
            return;
        }
        $requested = trim((string) ($payload->model ?? ''));
        if ($requested === '') {
            $connection->send(AnthropicProtocol::errorResponse(400, 'invalid_request_error', 'model is required.'));
            return;
        }
        $headers = new RequestHeaders(is_array($request->header()) ? $request->header() : []);
        $sessionId = $headers->get('x-claude-code-session-id');
        $automatic = $requested === 'claude-free-auto';
        $requirements = AnthropicProtocol::requirements($payload);
        $candidates = $this->router->candidates(
            $requested,
            $requirements,
            $sessionId,
            $automatic ? $this->config->maxRouteAttempts : 1,
        );
        if ($candidates === []) {
            $connection->send(AnthropicProtocol::errorResponse(
                400,
                'invalid_request_error',
                $automatic
                    ? 'No currently available free model supports this request.'
                    : 'Unknown, cooling down, or unavailable free model.',
            ));
            return;
        }

        $session = new MessageSession(
            id: ++$this->nextRequestId,
            connection: $connection,
            payload: $payload,
            headers: $headers,
            requestedModel: $requested,
            sessionId: $sessionId,
            automatic: $automatic,
            stream: (bool) ($payload->stream ?? false),
            candidates: $candidates,
            startedAt: microtime(true),
        );
        $this->sessions[$session->id] = $session;
        $this->counters['requests']++;
        $this->startSessionTimer($session);
        $this->attemptNext($session);
    }

    private function payload(mixed $connection, Request $request): ?object
    {
        $raw = $request->rawBody();
        if (strlen($raw) > $this->config->maxBodyBytes) {
            $connection->send(AnthropicProtocol::errorResponse(413, 'invalid_request_error', 'Request body is too large.'));
            return null;
        }
        $payload = json_decode($raw);
        if (!$payload instanceof \stdClass) {
            $connection->send(AnthropicProtocol::errorResponse(400, 'invalid_request_error', 'Request body must be a JSON object.'));
            return null;
        }
        return $payload;
    }

    private function attemptNext(MessageSession $session): void
    {
        if ($session->finished || $this->connectionClosed($session->connection)) {
            $this->cancel($session);
            return;
        }
        if ($session->candidateIndex >= count($session->candidates)
            || microtime(true) - $session->startedAt >= $this->config->routeTimeoutSeconds) {
            $this->exhausted($session);
            return;
        }

        $model = $session->candidates[$session->candidateIndex++];
        $provider = $this->providers[$model->provider];
        $session->attempts++;
        $attemptNumber = $session->attempts;
        try {
            $upstream = $provider->prepare('messages', $model, $session->payload, $session->headers);
        } catch (Throwable $exception) {
            $this->attemptFailed($session, $model, $provider->classify(0, '', $exception), '', null);
            return;
        }

        $this->limiter->run(
            $model->provider,
            strlen($upstream->body),
            function (callable $release) use ($session, $model, $provider, $upstream, $attemptNumber): void {
                if ($session->finished || $this->connectionClosed($session->connection)) {
                    $release();
                    $this->cancel($session);
                    return;
                }
                $this->dispatch($session, $model, $provider, $upstream, $attemptNumber, $release);
            },
            function () use ($session, $model): void {
                $this->counters['queue_rejected']++;
                $failure = new Failure(FailureKind::UPSTREAM_TRANSIENT, true, 5, 'model', 503);
                $this->attemptFailed($session, $model, $failure, '', null);
            },
        );
    }

    private function dispatch(
        MessageSession $session,
        ModelDescriptor $model,
        ProviderAdapter $provider,
        UpstreamRequest $upstream,
        int $attemptNumber,
        callable $release,
    ): void {
        $session->attemptStartedAt = microtime(true);
        $session->upstreamResponse = null;
        $session->gate = $session->stream ? new StreamGate($session->requestedModel) : null;
        $this->logger->event('upstream.request', [
            'request_id' => $session->id, 'provider' => $model->provider, 'model' => $model->upstreamId,
            'attempt' => $attemptNumber, 'stream' => $session->stream,
        ]);

        $released = false;
        $releaseOnce = function () use (&$released, $release): void {
            if (!$released) {
                $released = true;
                $release();
            }
        };

        $session->attemptTimers[$attemptNumber] = Timer::add(60, function () use (
            $session,
            $model,
            $attemptNumber,
        ): void {
            unset($session->attemptTimers[$attemptNumber]);
            if (!$this->current($session, $attemptNumber) || $session->modelCommitted) {
                return;
            }
            $failure = new Failure(FailureKind::UPSTREAM_TRANSIENT, true, 300, 'model', 504);
            $this->attemptFailed($session, $model, $failure, '', null);
        }, [], false);

        $options = [
            'method' => 'POST',
            'headers' => $upstream->headers,
            'data' => $upstream->body,
            'success' => function ($response) use ($session, $model, $provider, $attemptNumber, $releaseOnce): void {
                $releaseOnce();
                $this->clearAttemptTimer($session, $attemptNumber);
                if (!$this->current($session, $attemptNumber)) {
                    return;
                }
                try {
                    $this->handleUpstreamResponse($session, $model, $provider, $response);
                } catch (Throwable $exception) {
                    fwrite(STDERR, sprintf(
                        "[callback-error] request=%d provider=%s type=%s message=%s\n",
                        $session->id,
                        $model->provider,
                        $exception::class,
                        $this->secrets->redactString($exception->getMessage()),
                    ));
                    $this->attemptFailed(
                        $session,
                        $model,
                        new Failure(FailureKind::PROTOCOL_INVALID, true, 120, 'model', 502),
                        '',
                        null,
                    );
                }
            },
            'error' => function ($error) use ($session, $model, $provider, $attemptNumber, $releaseOnce): void {
                $releaseOnce();
                $this->clearAttemptTimer($session, $attemptNumber);
                if (!$this->current($session, $attemptNumber)) {
                    return;
                }
                try {
                    $failure = $provider->classify(0, '', $error);
                    if ($session->modelCommitted) {
                        $this->postCommitError($session, 'api_error', 'Upstream connection failed.');
                        return;
                    }
                    $this->attemptFailed($session, $model, $failure, '', null);
                } catch (Throwable $exception) {
                    fwrite(STDERR, sprintf(
                        "[callback-error] request=%d provider=%s type=%s\n",
                        $session->id,
                        $model->provider,
                        $exception::class,
                    ));
                    $this->exhausted($session);
                }
            },
        ];

        if ($session->stream) {
            $options['response'] = function ($response) use ($session, $attemptNumber): void {
                if ($this->current($session, $attemptNumber)) {
                    $session->upstreamResponse = $response;
                }
            };
            $options['progress'] = function (string $chunk) use ($session, $model, $attemptNumber): void {
                if (!$this->current($session, $attemptNumber) || !$session->gate instanceof StreamGate) {
                    return;
                }
                $this->clearAttemptTimer($session, $attemptNumber);
                $status = $session->upstreamResponse?->getStatusCode() ?? 0;
                if ($status < 200 || $status >= 300) {
                    return;
                }
                $result = $session->gate->feed($chunk);
                if ($result['output'] !== '') {
                    $this->sendStreamOutput($session, $result['output'], $result['committed_now']);
                    if ($result['committed_now']) {
                        $this->router->success($model, $session->sessionId, microtime(true) - $session->attemptStartedAt);
                        $session->successRecorded = true;
                    }
                }
            };
        }

        Timer::add(0.001, function () use (
            $upstream,
            $options,
            $session,
            $model,
            $provider,
            $attemptNumber,
            $releaseOnce,
        ): void {
            if (!$this->current($session, $attemptNumber)) {
                return;
            }
            try {
                $this->transport->request(
                    $upstream,
                    $options['response'] ?? static fn (): null => null,
                    $options['progress'] ?? static fn (): null => null,
                    $options['success'],
                    $options['error'],
                );
            } catch (Throwable $exception) {
                $releaseOnce();
                $this->clearAttemptTimer($session, $attemptNumber);
                $this->attemptFailed($session, $model, $provider->classify(0, '', $exception), '', null);
            }
        }, [], false);
    }

    private function handleUpstreamResponse(
        MessageSession $session,
        ModelDescriptor $model,
        ProviderAdapter $provider,
        object $response,
    ): void {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            $failure = $provider->classify($status, $body);
            if ($status === 429) {
                $retry = self::retryAfter($response->getHeaderLine('Retry-After'));
                if ($retry !== null) {
                    $failure = new Failure($failure->kind, true, max(1, (int) ceil($retry)), 'model', 429, $retry);
                }
            }
            $this->attemptFailed($session, $model, $failure, $body, $response);
            return;
        }

        if (!$session->stream) {
            if ($session->automatic && !AnthropicProtocol::usefulJson($body)) {
                $failure = new Failure(FailureKind::EMPTY_RESPONSE, true, 300, 'model', 502);
                $this->attemptFailed($session, $model, $failure, $body, $response);
                return;
            }
            $this->router->success($model, $session->sessionId, microtime(true) - $session->attemptStartedAt);
            $session->successRecorded = true;
            $rewritten = AnthropicProtocol::rewriteJson($body, $session->requestedModel);
            $session->connection->send(new Response($status, AnthropicProtocol::upstreamHeaders($response), $rewritten));
            $this->complete($session, $model, $status);
            return;
        }

        if (!$session->gate instanceof StreamGate) {
            $this->postCommitError($session, 'api_error', 'Missing stream state.');
            return;
        }
        $tail = $session->gate->finish();
        if (!$session->modelCommitted && ($tail['empty'] || $tail['error'])) {
            $failure = new Failure(
                $tail['error'] ? FailureKind::PROTOCOL_INVALID : FailureKind::EMPTY_RESPONSE,
                true,
                300,
                'model',
                502,
            );
            $this->attemptFailed($session, $model, $failure, '', $response);
            return;
        }
        if ($tail['output'] !== '') {
            $this->sendStreamOutput($session, $tail['output'], false);
        }
        if (!$session->headersSent) {
            $this->ensureStreamHeaders($session);
        }
        $session->connection->send(new Chunk(''));
        if ($tail['error']) {
            $this->fail($session);
            return;
        }
        if (!$session->successRecorded) {
            $this->router->success($model, $session->sessionId, microtime(true) - $session->attemptStartedAt);
            $session->successRecorded = true;
        }
        $this->complete($session, $model, 200);
    }

    private function attemptFailed(
        MessageSession $session,
        ModelDescriptor $model,
        Failure $failure,
        string $body,
        ?object $response,
    ): void {
        if ($session->finished) {
            return;
        }
        $this->router->failure($model, $failure);
        if ($failure->kind === FailureKind::RATE_LIMIT) {
            $session->rateLimited++;
        }
        $this->logger->event('upstream.failure', [
            'request_id' => $session->id, 'provider' => $model->provider, 'model' => $model->upstreamId,
            'status' => $failure->status, 'failure' => $failure->kind->value, 'attempt' => $session->attempts,
        ]);
        if ($session->automatic && $failure->safeToFallback
            && $session->candidateIndex < count($session->candidates)) {
            $this->counters['fallbacks']++;
            $session->gate = null;
            $session->upstreamResponse = null;
            $this->attemptNext($session);
            return;
        }

        if ($response !== null && !$session->headersSent) {
            $session->connection->send(new Response(
                $response->getStatusCode(),
                AnthropicProtocol::upstreamHeaders($response),
                $body,
            ));
            $this->fail($session);
            return;
        }
        if (!$session->automatic && !$session->headersSent) {
            $session->connection->send(AnthropicProtocol::errorResponse(502, 'api_error', 'Upstream connection failed.'));
            $this->fail($session);
            return;
        }
        $this->exhausted($session);
    }

    private function exhausted(MessageSession $session): void
    {
        if ($session->finished) {
            return;
        }
        $status = $session->rateLimited >= max(1, $session->attempts) ? 429 : 503;
        $type = $status === 429 ? 'rate_limit_error' : 'overloaded_error';
        $message = $status === 429
            ? 'All compatible free models are currently rate limited.'
            : 'All compatible free models are currently unavailable.';
        if ($session->headersSent) {
            $session->connection->send(new Chunk(AnthropicProtocol::streamError($type, $message)));
            $session->connection->send(new Chunk(''));
        } else {
            $session->connection->send(AnthropicProtocol::errorResponse($status, $type, $message));
        }
        $this->fail($session);
    }

    private function sendStreamOutput(MessageSession $session, string $output, bool $commitNow): void
    {
        $this->ensureStreamHeaders($session);
        if ($commitNow) {
            $session->modelCommitted = true;
        }
        $session->connection->send(new Chunk($output));
    }

    private function ensureStreamHeaders(MessageSession $session): void
    {
        if ($session->headersSent) {
            return;
        }
        $session->headersSent = true;
        $session->connection->send(new Response(200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'Transfer-Encoding' => 'chunked',
            'X-Accel-Buffering' => 'no',
        ], ''));
    }

    private function postCommitError(MessageSession $session, string $type, string $message): void
    {
        $this->ensureStreamHeaders($session);
        $session->connection->send(new Chunk(AnthropicProtocol::streamError($type, $message)));
        $session->connection->send(new Chunk(''));
        $this->fail($session);
    }

    private function startSessionTimer(MessageSession $session): void
    {
        $session->lastPingAt = microtime(true);
        $session->timer = Timer::add(1, function () use ($session): void {
            if ($session->finished) {
                return;
            }
            // On Windows, Workerman can report a completed HTTP request connection as
            // CLOSED while the client is still waiting for the response. Cancelling here
            // drops valid non-streaming Claude Code requests before their upstream reply.
            if (PHP_OS_FAMILY !== 'Windows' && $this->connectionClosed($session->connection)) {
                $this->cancel($session);
                return;
            }
            $now = microtime(true);
            if ($now - $session->startedAt >= $this->config->routeTimeoutSeconds) {
                if ($session->headersSent) {
                    $this->postCommitError($session, 'timeout_error', 'The free-model route timed out.');
                } else {
                    $session->connection->send(AnthropicProtocol::errorResponse(504, 'timeout_error', 'The free-model route timed out.'));
                    $this->fail($session);
                }
                return;
            }
            if (!$session->stream || !$session->gate instanceof StreamGate) {
                return;
            }
            $status = $session->upstreamResponse?->getStatusCode() ?? 0;
            if ($status >= 200 && $status < 300) {
                $forced = $session->gate->forceIfDue();
                if ($forced['output'] !== '') {
                    $this->sendStreamOutput($session, $forced['output'], true);
                }
                if (!$session->modelCommitted && $now - $session->lastPingAt >= 15) {
                    $this->ensureStreamHeaders($session);
                    $session->connection->send(new Chunk("event: ping\ndata: {\"type\":\"ping\"}\n\n"));
                    $session->lastPingAt = $now;
                }
            }
        });
    }

    private function current(MessageSession $session, int $attempt): bool
    {
        return !$session->finished && isset($this->sessions[$session->id]) && $session->attempts === $attempt;
    }

    private function complete(MessageSession $session, ModelDescriptor $model, int $status): void
    {
        $this->counters['completed']++;
        $this->logger->event('downstream.complete', [
            'request_id' => $session->id, 'provider' => $model->provider, 'model' => $model->upstreamId,
            'status' => $status, 'duration_ms' => (int) round((microtime(true) - $session->startedAt) * 1000),
        ]);
        $this->finishSession($session);
    }

    private function fail(MessageSession $session): void
    {
        $this->counters['failed']++;
        $this->finishSession($session);
    }

    private function cancel(MessageSession $session): void
    {
        if ($session->finished) {
            return;
        }
        $this->counters['cancelled']++;
        $this->finishSession($session);
    }

    private function finishSession(MessageSession $session): void
    {
        $session->finished = true;
        if ($session->timer !== null) {
            Timer::del($session->timer);
            $session->timer = null;
        }
        foreach (array_keys($session->attemptTimers) as $attempt) {
            $this->clearAttemptTimer($session, (int) $attempt);
        }
        unset($this->sessions[$session->id]);
    }

    private function clearAttemptTimer(MessageSession $session, int $attempt): void
    {
        if (!array_key_exists($attempt, $session->attemptTimers)) {
            return;
        }
        Timer::del($session->attemptTimers[$attempt]);
        unset($session->attemptTimers[$attempt]);
    }

    /** @return array<string, mixed> */
    private function health(): array
    {
        return [
            'status' => $this->catalog->all() === [] ? 'degraded' : 'ok',
            'service' => 'claude-free-gateway',
            'listen' => $this->config->host . ':' . $this->config->port,
            'catalog' => $this->catalog->status(),
            'router' => $this->router->status(),
            'limiter' => $this->limiter->status(),
            'in_flight_sessions' => count($this->sessions),
            'counters' => $this->counters,
        ];
    }

    private function newHttpClient(): Client
    {
        return new Client([
            'connect_timeout' => 10,
            'timeout' => $this->config->attemptTimeoutSeconds,
            'keepalive_timeout' => 0,
            'max_conn_per_addr' => 1,
        ]);
    }

    private function connectionClosed(mixed $connection): bool
    {
        return $connection instanceof TcpConnection && $connection->getStatus() === TcpConnection::STATUS_CLOSED;
    }

    private static function retryAfter(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $time = strtotime($value);
        return $time === false ? null : max(0.0, (float) ($time - time()));
    }
}
