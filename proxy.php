<?php

declare(strict_types=1);

use Workerman\Connection\TcpConnection;
use Workerman\Http\Client;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

final class ProxyConfig
{
    public function __construct(
        public readonly string $apiKey,
        public readonly string $modelsFile,
        public readonly int $upstreamTimeout,
        public readonly int $initialConcurrency,
        public readonly int $maxConcurrency,
        public readonly int $queueCapacity,
        public readonly int $queueMaxWait,
        public readonly int $queueMaxBytes,
        public readonly int $retryMax,
        public readonly int $retryBaseMs,
        public readonly int $retryMaxDelay,
        public readonly bool $logEnabled,
        public readonly bool $logBodyEnabled,
        public readonly int $logMaxBytes,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $env = self::loadEnv($path);
        $initialConcurrency = self::envInt($env, 'BAI_INITIAL_CONCURRENCY', 2, 1, 64);

        return new self(
            apiKey: trim($env['BAI_API_KEY'] ?? ''),
            modelsFile: trim($env['BAI_MODELS_FILE'] ?? '') ?: 'models.json',
            upstreamTimeout: self::envInt($env, 'UPSTREAM_TIMEOUT', 180, 30),
            initialConcurrency: $initialConcurrency,
            maxConcurrency: self::envInt($env, 'BAI_MAX_CONCURRENCY', 8, $initialConcurrency, 64),
            queueCapacity: self::envInt($env, 'BAI_QUEUE_CAPACITY', 64, 1, 10000),
            queueMaxWait: self::envInt($env, 'BAI_QUEUE_MAX_WAIT', 120, 1, 3600),
            queueMaxBytes: self::envInt($env, 'BAI_QUEUE_MAX_BYTES', 67108864, 1024, 268435456),
            retryMax: self::envInt($env, 'BAI_RETRY_MAX', 3, 0, 10),
            retryBaseMs: self::envInt($env, 'BAI_RETRY_BASE_MS', 1000, 100, 60000),
            retryMaxDelay: self::envInt($env, 'BAI_RETRY_MAX_DELAY', 30, 1, 300),
            logEnabled: self::envBool($env, 'BAI_LOG_ENABLED', true),
            logBodyEnabled: self::envBool($env, 'BAI_LOG_BODY', true),
            logMaxBytes: self::envInt($env, 'BAI_LOG_MAX_BYTES', 65536, 0, 1048576),
        );
    }

    private static function loadEnv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $env = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }
        return $env;
    }

    private static function envInt(array $env, string $key, int $default, int $min, int $max = PHP_INT_MAX): int
    {
        $value = filter_var($env[$key] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value === null) {
            $value = $default;
        }
        return max($min, min($max, $value));
    }

    private static function envBool(array $env, string $key, bool $default): bool
    {
        $value = strtolower(trim((string) ($env[$key] ?? '')));
        return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}

final class ModelCatalog
{
    private const DEFAULT_MAX_TOKENS = 131072;

    private array $models;

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Model configuration file not found: {$path}");
        }

        $contents = file_get_contents($path);
        $config = json_decode($contents === false ? '' : $contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid model configuration JSON: {$path}");
        }

        $definitions = $config['models'] ?? $config;
        if (!is_array($definitions) || $definitions === []) {
            throw new RuntimeException("Model configuration must contain a non-empty models array: {$path}");
        }

        return new self($definitions);
    }

    private function __construct(array $definitions)
    {
        $this->models = [];
        $seen = [];
        foreach (array_values($definitions) as $index => $definition) {
            if (is_string($definition)) {
                $definition = ['upstream' => $definition];
            }
            if (!is_array($definition)) {
                throw new RuntimeException('Each model definition must be a JSON object or string.');
            }

            $upstream = trim((string) ($definition['upstream'] ?? $definition['name'] ?? ''));
            if ($upstream === '') {
                throw new RuntimeException(sprintf('Model definition at index %d is missing upstream.', $index));
            }
            if (isset($seen[$upstream])) {
                throw new RuntimeException("Duplicate upstream model in model configuration: {$upstream}");
            }
            $seen[$upstream] = true;

            $maxTokens = filter_var($definition['max_tokens'] ?? self::DEFAULT_MAX_TOKENS, FILTER_VALIDATE_INT);
            if ($maxTokens === false || $maxTokens <= 0) {
                $maxTokens = self::DEFAULT_MAX_TOKENS;
            }

            $model = [
                'alias' => 'claude-sonnet-1-' . ($index + 1),
                'upstream' => $upstream,
                'name' => trim((string) ($definition['display_name'] ?? $definition['name'] ?? $upstream)) ?: $upstream,
                'max_tokens' => $maxTokens,
            ];
            if (($definition['repair_sse'] ?? false) === true) {
                $model['repair_sse'] = true;
            }
            $this->models[] = $model;
        }
    }

    public function find(string $requested): ?array
    {
        $requested = trim($requested);
        foreach ($this->models as $model) {
            if ($model['alias'] === $requested) {
                return ['model' => $model, 'client_alias' => $model['alias']];
            }
        }
        return null;
    }

    public function count(): int
    {
        return count($this->models);
    }

    public function discovery(): array
    {
        return array_map(static fn (array $model): array => [
            'id' => $model['alias'],
            'type' => 'model',
            'created_at' => '1970-01-01T00:00:00Z',
            'display_name' => $model['name'],
            'max_input_tokens' => null,
            'max_tokens' => $model['max_tokens'],
            'capabilities' => null,
        ], $this->models);
    }
}

final class BaiProtocol
{
    public static function jsonResponse(int $status, array $body): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );
    }

    public static function errorResponse(int $status, string $message): Response
    {
        return self::jsonResponse($status, [
            'type' => 'error',
            'error' => ['type' => 'api_error', 'message' => $message],
        ]);
    }

    public static function upstreamHeaders(object $response): array
    {
        $headers = [
            'Content-Type' => $response->getHeaderLine('Content-Type') ?: 'application/json; charset=utf-8',
        ];
        foreach (['Retry-After', 'X-Request-ID', 'Request-ID'] as $header) {
            $value = $response->getHeaderLine($header);
            if ($value !== '') {
                $headers[$header] = $value;
            }
        }
        return $headers;
    }

    public static function forwardResponse(mixed $connection, object $response, string $clientModel): string
    {
        $body = self::rewriteJson((string) $response->getBody(), $clientModel);
        $connection->send(new Response($response->getStatusCode(), self::upstreamHeaders($response), $body));
        return $body;
    }

    public static function rewriteJson(string $body, string $clientModel): string
    {
        $decoded = json_decode($body);
        if (!is_object($decoded) && !is_array($decoded)) {
            return $body;
        }
        return json_encode(self::rewriteModel($decoded, $clientModel), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: $body;
    }

    public static function rewriteSse(string &$pending, string $incoming, array &$state, string $clientModel): string
    {
        $pending .= $incoming;
        $output = '';

        while (preg_match('/\r?\n\r?\n/', $pending, $match, PREG_OFFSET_CAPTURE)) {
            $record = substr($pending, 0, $match[0][1]);
            $pending = substr($pending, $match[0][1] + strlen($match[0][0]));
            $lines = preg_split('/\r?\n/', $record) ?: [];

            foreach ($lines as &$line) {
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = ltrim(substr($line, 5));
                $event = json_decode($data);
                if (!$event instanceof stdClass) {
                    continue;
                }

                $type = (string) ($event->type ?? '');
                if ($type === 'message_start') {
                    $state['started'] = true;
                } elseif ($type === 'message_stop') {
                    $state['stopped'] = true;
                } elseif (isset($event->index) && is_numeric($event->index)) {
                    $index = (int) $event->index;
                    if ($type === 'content_block_start') {
                        $state['blocks'][$index] = $index;
                    } elseif ($type === 'content_block_stop') {
                        unset($state['blocks'][$index]);
                    }
                }

                $line = 'data: ' . (json_encode(
                    self::rewriteModel($event, $clientModel),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ) ?: $data);
            }
            unset($line);
            $output .= implode("\n", $lines) . "\n\n";
        }

        return $output;
    }

    public static function finishSse(array &$state, bool $repair): string
    {
        if (!$repair || !$state['started'] || $state['stopped']) {
            return '';
        }

        $output = '';
        foreach (array_reverse($state['blocks']) as $index) {
            $output .= "event: content_block_stop\n";
            $output .= 'data: ' . json_encode(['type' => 'content_block_stop', 'index' => $index]) . "\n\n";
        }
        $output .= "event: message_delta\n";
        $output .= 'data: ' . json_encode([
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null],
            'usage' => ['output_tokens' => 0],
        ]) . "\n\n";
        $output .= "event: message_stop\n";
        $output .= "data: {\"type\":\"message_stop\"}\n\n";
        $state['stopped'] = true;
        return $output;
    }

    public static function streamError(string $message): string
    {
        return "event: error\n" . 'data: ' . json_encode([
            'type' => 'error',
            'error' => ['type' => 'api_error', 'message' => $message],
        ]) . "\n\n";
    }

    public static function tokenCount(mixed $payload): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $length = function_exists('mb_strlen') ? mb_strlen($json, 'UTF-8') : strlen($json);
        return max(1, (int) ceil($length / 4));
    }

    public static function retryAfterSeconds(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (float) $value;
        }
        $time = strtotime($value);
        return $time === false ? null : max(0.0, (float) ($time - time()));
    }

    public static function connectionClosed(mixed $connection): bool
    {
        return $connection instanceof TcpConnection
            && $connection->getStatus() === TcpConnection::STATUS_CLOSED;
    }

    private static function rewriteModel(mixed $value, string $clientModel): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $key === 'model' && is_string($item)
                    ? $clientModel
                    : self::rewriteModel($item, $clientModel);
            }
            return $value;
        }
        if ($value instanceof stdClass) {
            foreach ($value as $key => $item) {
                $value->{$key} = $key === 'model' && is_string($item)
                    ? $clientModel
                    : self::rewriteModel($item, $clientModel);
            }
        }
        return $value;
    }
}

final class RequestLog
{
    private mixed $handle;

    public function __construct(
        private readonly int $requestId,
        private readonly ?string $path,
        mixed $handle,
        private readonly bool $includeBody,
        private readonly int $maxBytes,
    ) {
        $this->handle = $handle;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function body(string $body): array
    {
        if (!is_resource($this->handle)) {
            return [];
        }

        $result = ['bytes' => strlen($body)];
        if (!$this->includeBody) {
            return $result;
        }
        if (strlen($body) <= $this->maxBytes) {
            $json = json_decode($body);
            if (json_last_error() === JSON_ERROR_NONE) {
                $result['json'] = $json;
                return $result;
            }
        }

        $result['text'] = substr($body, 0, $this->maxBytes);
        if (strlen($body) > $this->maxBytes) {
            $result['truncated'] = true;
        }
        return $result;
    }

    public function event(string $event, array $context = []): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        $entry = json_encode([
            'time' => gmdate('c'),
            'event' => $event,
            'request' => $this->requestId,
            ...$context,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($entry === false) {
            return;
        }

        $content = $entry . PHP_EOL . str_repeat('=', 88) . PHP_EOL . PHP_EOL;
        @flock($this->handle, LOCK_EX);
        $written = @fwrite($this->handle, $content);
        @fflush($this->handle);
        @flock($this->handle, LOCK_UN);
        if ($written === false || $written !== strlen($content)) {
            $this->close();
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            @fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}

final class SessionLogFactory
{
    private bool $available;
    private bool $warned = false;

    public function __construct(
        bool $enabled,
        private readonly string $directory,
        private readonly bool $includeBody,
        private readonly int $maxBytes,
    ) {
        $this->available = $enabled;
        if ($enabled && !is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            $this->available = false;
            $this->warn('Unable to create the session log directory.');
        }
    }

    public function open(int $requestId, float $receivedAt): RequestLog
    {
        if (!$this->available) {
            return new RequestLog($requestId, null, null, $this->includeBody, $this->maxBytes);
        }

        $seconds = (int) floor($receivedAt);
        $micros = max(0, min(999999, (int) round(($receivedAt - $seconds) * 1000000)));
        $stamp = gmdate('Ymd\THis', $seconds) . sprintf('.%06dZ', $micros);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $suffix = bin2hex(random_bytes(4));
            } catch (Throwable) {
                $suffix = sprintf('%08x', mt_rand());
            }
            $path = $this->directory . DIRECTORY_SEPARATOR . sprintf(
                '%s-r%010d-%s.log',
                $stamp,
                $requestId,
                $suffix,
            );
            $handle = @fopen($path, 'x+b');
            if (is_resource($handle)) {
                return new RequestLog($requestId, $path, $handle, $this->includeBody, $this->maxBytes);
            }
        }

        $this->warn('Unable to create a session log file; logging is disabled for this request.');
        $this->available = false;
        return new RequestLog($requestId, null, null, $this->includeBody, $this->maxBytes);
    }

    private function warn(string $message): void
    {
        if ($this->warned) {
            return;
        }
        $this->warned = true;
        fwrite(STDERR, '[WARN] ' . $message . PHP_EOL);
    }
}

final class RequestSession
{
    public string $state = 'queued';
    public int $retries = 0;
    public int $attemptSequence = 0;
    public int $activeAttempt = 0;
    public bool $headersCommitted = false;
    public int $streamBytes = 0;
    public string $pendingSse = '';
    public array $sseState = ['started' => false, 'stopped' => false, 'blocks' => []];

    public function __construct(
        public readonly int $id,
        public readonly float $receivedAt,
        public readonly float $deadline,
        public readonly mixed $connection,
        public readonly string $upstreamBody,
        public readonly array $headers,
        public readonly bool $stream,
        public readonly array $route,
        public readonly array $model,
        public readonly RequestLog $log,
    ) {
    }
}

final class BaiProxyServer
{
    private const HOST = '127.0.0.1';
    private const PORT = 8787;
    private const UPSTREAM_URL = 'https://api.b.ai/v1/messages';

    private ?Client $client = null;
    private array $sessions = [];
    private array $ready = [];
    private int $active = 0;
    private int $pending = 0;
    private int $queueBytes = 0;
    private int $window;
    private int $clean = 0;
    private float $cooldownUntil = 0.0;
    private float $wakeAt = 0.0;
    private mixed $wakeTimer = null;
    private int $nextRequestId = 0;
    private array $counters = [
        'completed' => 0,
        'rate_limited' => 0,
        'retries' => 0,
        'rejected' => 0,
        'expired' => 0,
        'cancelled' => 0,
    ];

    public function __construct(
        private readonly ProxyConfig $config,
        private readonly ModelCatalog $models,
        private readonly SessionLogFactory $logs,
    ) {
        $this->window = $config->initialConcurrency;
    }

    public function run(): void
    {
        $worker = new Worker(sprintf('http://%s:%d', self::HOST, self::PORT));
        $worker->name = 'bai-proxy';
        $worker->count = 1;
        $worker->onWorkerStart = fn () => $this->startClient();
        $worker->onMessage = fn ($connection, Request $request) => $this->handle($connection, $request);

        fwrite(STDOUT, sprintf("B.AI proxy listening on http://%s:%d\n", self::HOST, self::PORT));
        Worker::runAll();
    }

    private function startClient(): void
    {
        $this->client = new Client([
            'connect_timeout' => 30,
            'timeout' => $this->config->upstreamTimeout,
            'keepalive_timeout' => 30,
            'max_conn_per_addr' => $this->config->maxConcurrency,
        ]);

        Timer::add(1, function (): void {
            foreach ($this->sessions as $id => $session) {
                if (!BaiProtocol::connectionClosed($session->connection) || $session->state === 'in_flight') {
                    continue;
                }
                $discarded = $this->discard((int) $id);
                if ($discarded !== null) {
                    $this->counters['cancelled']++;
                    $discarded->log->event('downstream.disconnected', ['state' => $discarded->state]);
                    $discarded->log->close();
                }
            }
            $this->pump();
        });
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

        if ($method === 'GET' && ($path === '/' || $path === '/health')) {
            $connection->send(BaiProtocol::jsonResponse(200, $this->health()));
            return;
        }

        if (in_array($method, ['GET', 'POST', 'HEAD'], true) && $path === '/api/hello') {
            $connection->send($method === 'HEAD'
                ? new Response(200, ['Content-Type' => 'application/json; charset=utf-8'])
                : BaiProtocol::jsonResponse(200, ['status' => 'ok', 'upstream' => 'B.AI', 'model_count' => $this->models->count()]));
            return;
        }

        if ($method === 'GET' && $path === '/v1/models') {
            $data = $this->models->discovery();
            $connection->send(BaiProtocol::jsonResponse(200, [
                'data' => $data,
                'first_id' => $data[0]['id'] ?? null,
                'has_more' => false,
                'last_id' => $data === [] ? null : $data[array_key_last($data)]['id'],
            ]));
            return;
        }

        if ($method !== 'POST' || !in_array($path, ['/v1/messages', '/v1/messages/count_tokens'], true)) {
            $connection->send(BaiProtocol::errorResponse(404, 'Only B.AI message and discovery endpoints are available.'));
            return;
        }

        if ($path === '/v1/messages/count_tokens') {
            $payload = json_decode($request->rawBody());
            if (!$payload instanceof stdClass) {
                $connection->send(BaiProtocol::errorResponse(400, 'The request body must be a valid JSON object.'));
                return;
            }
            $connection->send(BaiProtocol::jsonResponse(200, ['input_tokens' => BaiProtocol::tokenCount($payload)]));
            return;
        }

        $this->handleMessage($connection, $request);
    }

    private function handleMessage(mixed $connection, Request $request): void
    {
        $receivedAt = microtime(true);
        $id = ++$this->nextRequestId;
        $log = $this->logs->open($id, $receivedAt);
        $rawBody = $request->rawBody();
        $payload = json_decode($rawBody);
        $log->event('downstream.request', [
            'method' => 'POST',
            'path' => '/v1/messages',
            'model' => $payload instanceof stdClass ? (string) ($payload->model ?? '') : '',
            'stream' => $payload instanceof stdClass ? (bool) ($payload->stream ?? false) : false,
            'body' => $log->body($rawBody),
        ]);

        if (!$payload instanceof stdClass) {
            $this->rejectLocal($connection, $log, 400, 'The request body must be a valid JSON object.');
            return;
        }

        $route = $this->models->find((string) ($payload->model ?? ''));
        if ($route === null) {
            $this->rejectLocal($connection, $log, 400, 'This model is not offered by this B.AI proxy.');
            return;
        }
        if (!$this->client instanceof Client) {
            $this->rejectLocal($connection, $log, 500, 'B.AI client is unavailable.');
            return;
        }

        $model = $route['model'];
        $requestedTokens = (int) ($payload->max_tokens ?? 0);
        if ($requestedTokens <= 0) {
            $this->rejectLocal($connection, $log, 400, 'max_tokens must be a positive integer.');
            return;
        }

        // stdClass preserves empty JSON Schema objects ({}) when re-encoding.
        $payload->model = $model['upstream'];
        $payload->max_tokens = $requestedTokens <= 2 ? 16 : min($requestedTokens, $model['max_tokens']);
        $stream = (bool) ($payload->stream ?? false);
        $upstreamBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($upstreamBody === false) {
            $this->rejectLocal($connection, $log, 400, 'Unable to encode the request body.');
            return;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->config->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => $stream ? 'text/event-stream' : 'application/json',
            'anthropic-version' => $request->header('anthropic-version', '2023-06-01'),
        ];
        if (($beta = $request->header('anthropic-beta', '')) !== '') {
            $headers['anthropic-beta'] = $beta;
        }

        $session = new RequestSession(
            id: $id,
            receivedAt: $receivedAt,
            deadline: $receivedAt + $this->config->queueMaxWait,
            connection: $connection,
            upstreamBody: $upstreamBody,
            headers: $headers,
            stream: $stream,
            route: $route,
            model: $model,
            log: $log,
        );
        $this->enqueue($session);
    }

    private function rejectLocal(mixed $connection, RequestLog $log, int $status, string $message): void
    {
        $connection->send(BaiProtocol::errorResponse($status, $message));
        $log->event('downstream.response', [
            'status' => $status,
            'body' => $log->body($message),
        ]);
        $log->close();
    }

    private function health(): array
    {
        return [
            'status' => $this->cooldownUntil > microtime(true) || $this->pending >= $this->config->queueCapacity ? 'degraded' : 'ok',
            'upstream' => 'B.AI',
            'model_count' => $this->models->count(),
            'active' => $this->active,
            'queued' => $this->pending,
            'queue_bytes' => $this->queueBytes,
            'effective_concurrency' => $this->window,
            'max_concurrency' => $this->config->maxConcurrency,
            'cooldown_ms' => max(0, (int) round(($this->cooldownUntil - microtime(true)) * 1000)),
            'counters' => $this->counters,
        ];
    }

    private function enqueue(RequestSession $session): void
    {
        $bytes = strlen($session->upstreamBody);
        if ($this->pending >= $this->config->queueCapacity || $this->queueBytes + $bytes > $this->config->queueMaxBytes) {
            $this->counters['rejected']++;
            $this->rejectLocal($session->connection, $session->log, 503, 'B.AI proxy queue is full.');
            return;
        }

        $this->sessions[$session->id] = $session;
        $this->ready[] = $session->id;
        $this->pending++;
        $this->queueBytes += $bytes;
        $session->log->event('queue.enqueued', [
            'queued' => $this->pending,
            'queue_bytes' => $this->queueBytes,
        ]);
        $this->pump();
    }

    private function pump(): void
    {
        $now = microtime(true);
        if ($this->cooldownUntil > $now) {
            $this->schedulePump($this->cooldownUntil);
            return;
        }

        while ($this->active < $this->window && $this->ready !== []) {
            $id = (int) array_shift($this->ready);
            $session = $this->sessions[$id] ?? null;
            if (!$session instanceof RequestSession || $session->state !== 'queued') {
                continue;
            }
            if (BaiProtocol::connectionClosed($session->connection)) {
                $discarded = $this->discard($id);
                if ($discarded !== null) {
                    $this->counters['cancelled']++;
                    $discarded->log->event('downstream.disconnected', ['state' => 'queued']);
                    $discarded->log->close();
                }
                continue;
            }
            if ($now > $session->deadline) {
                $expired = $this->discard($id);
                if ($expired !== null) {
                    $this->counters['expired']++;
                    $expired->connection->send(BaiProtocol::errorResponse(503, 'Request expired in the B.AI queue.'));
                    $expired->log->event('downstream.response', [
                        'status' => 503,
                        'body' => $expired->log->body('Request expired in the B.AI queue.'),
                    ]);
                    $expired->log->close();
                }
                continue;
            }

            $this->pending--;
            $this->queueBytes -= strlen($session->upstreamBody);
            $this->dispatch($session);
        }
    }

    private function schedulePump(float $when): void
    {
        if ($this->wakeAt > 0 && $this->wakeAt <= $when) {
            return;
        }
        if ($this->wakeTimer !== null) {
            Timer::del($this->wakeTimer);
        }
        $this->wakeAt = $when;
        $this->wakeTimer = Timer::add(max(0.001, $when - microtime(true)), function (): void {
            $this->wakeAt = 0.0;
            $this->wakeTimer = null;
            $this->pump();
        }, [], false);
    }

    private function discard(int $id): ?RequestSession
    {
        $session = $this->sessions[$id] ?? null;
        if (!$session instanceof RequestSession) {
            return null;
        }
        if ($session->state !== 'in_flight') {
            $this->pending--;
            $this->queueBytes -= strlen($session->upstreamBody);
        }
        $session->state = 'discarded';
        unset($this->sessions[$id]);
        return $session;
    }

    private function completeAttempt(int $id, int $attempt): ?RequestSession
    {
        $session = $this->sessions[$id] ?? null;
        if (!$session instanceof RequestSession || $session->activeAttempt !== $attempt) {
            return null;
        }
        $session->state = 'completed';
        $this->active--;
        unset($this->sessions[$id]);
        $this->pump();
        return $session;
    }

    private function dispatch(RequestSession $session): void
    {
        if (BaiProtocol::connectionClosed($session->connection)) {
            unset($this->sessions[$session->id]);
            $this->counters['cancelled']++;
            $session->log->event('downstream.disconnected', ['state' => 'dispatch']);
            $session->log->close();
            return;
        }

        $session->state = 'in_flight';
        $attempt = ++$session->attemptSequence;
        $session->activeAttempt = $attempt;
        $this->active++;

        $options = [
            'method' => 'POST',
            'headers' => $session->headers,
            'data' => $session->upstreamBody,
            'success' => function ($response) use ($session, $attempt): void {
                $this->handleUpstreamSuccess($session, $attempt, $response);
            },
            'error' => function ($error) use ($session, $attempt): void {
                $this->handleUpstreamError($session, $attempt, $error);
            },
        ];

        if ($session->stream) {
            $options['response'] = function ($response) use ($session, $attempt): void {
                if (!$this->isCurrentAttempt($session, $attempt)) {
                    return;
                }
                $session->log->event('upstream.stream_headers', [
                    'attempt' => $attempt,
                    'status' => $response->getStatusCode(),
                    'content_type' => $response->getHeaderLine('Content-Type'),
                    'retry_after' => $response->getHeaderLine('Retry-After'),
                ]);
                if ($response->getStatusCode() >= 300 || BaiProtocol::connectionClosed($session->connection)) {
                    return;
                }
                $session->headersCommitted = true;
                $session->connection->send(new Response(200, [
                    'Content-Type' => 'text/event-stream; charset=utf-8',
                    'Cache-Control' => 'no-cache, no-transform',
                    'Connection' => 'keep-alive',
                    'Transfer-Encoding' => 'chunked',
                    'X-Accel-Buffering' => 'no',
                ], ''));
                $session->log->event('downstream.stream_headers', ['status' => 200]);
            };

            $options['progress'] = function (string $chunk) use ($session, $attempt): void {
                if (!$this->isCurrentAttempt($session, $attempt)
                    || !$session->headersCommitted
                    || BaiProtocol::connectionClosed($session->connection)) {
                    return;
                }
                $session->log->event('upstream.stream_chunk', [
                    'attempt' => $attempt,
                    'body' => $session->log->body($chunk),
                ]);
                $session->streamBytes += strlen($chunk);
                $rewritten = BaiProtocol::rewriteSse(
                    $session->pendingSse,
                    $chunk,
                    $session->sseState,
                    $session->route['client_alias'],
                );
                if ($rewritten !== '') {
                    $session->connection->send(new Chunk($rewritten));
                    $session->log->event('downstream.stream_chunk', [
                        'body' => $session->log->body($rewritten),
                    ]);
                }
            };
        }

        try {
            $session->log->event('upstream.request', [
                'attempt' => $attempt,
                'endpoint' => self::UPSTREAM_URL,
                'model' => $session->model['upstream'],
                'stream' => $session->stream,
                'body' => $session->log->body($session->upstreamBody),
            ]);
            $this->client?->request(self::UPSTREAM_URL, $options);
        } catch (Throwable $exception) {
            $finished = $this->completeAttempt($session->id, $attempt);
            if ($finished === null) {
                return;
            }
            $finished->log->event('upstream.error', ['message' => $exception->getMessage()]);
            if (!BaiProtocol::connectionClosed($finished->connection)) {
                $finished->connection->send(BaiProtocol::errorResponse(502, 'B.AI connection failed.'));
                $finished->log->event('downstream.response', [
                    'status' => 502,
                    'body' => $finished->log->body('B.AI connection failed.'),
                ]);
            } else {
                $this->counters['cancelled']++;
                $finished->log->event('downstream.disconnected', ['state' => 'in_flight']);
            }
            $finished->log->close();
        }
    }

    private function handleUpstreamSuccess(RequestSession $session, int $attempt, object $response): void
    {
        if (!$this->isCurrentAttempt($session, $attempt)) {
            return;
        }

        $status = $response->getStatusCode();
        if (!$session->stream || $status >= 300) {
            $session->log->event('upstream.response', [
                'attempt' => $attempt,
                'status' => $status,
                'content_type' => $response->getHeaderLine('Content-Type'),
                'retry_after' => $response->getHeaderLine('Retry-After'),
                'body' => $session->log->body((string) $response->getBody()),
            ]);
        }
        if ($status === 429 && !$session->headersCommitted) {
            $this->retry($session, $attempt, $response);
            return;
        }

        $finished = $this->completeAttempt($session->id, $attempt);
        if ($finished === null) {
            return;
        }
        if (BaiProtocol::connectionClosed($finished->connection)) {
            $this->counters['cancelled']++;
            $finished->log->event('downstream.disconnected', ['state' => 'in_flight']);
            $finished->log->close();
            return;
        }

        $this->counters['completed']++;
        if ($status >= 200 && $status < 300) {
            $this->clean++;
            if ($this->clean >= 8 && $this->window < $this->config->maxConcurrency) {
                $this->window++;
                $this->clean = 0;
            }
        }

        if (!$finished->stream) {
            $headers = BaiProtocol::upstreamHeaders($response);
            $body = BaiProtocol::forwardResponse($finished->connection, $response, $finished->route['client_alias']);
            $finished->log->event('downstream.response', [
                'status' => $status,
                'content_type' => $headers['Content-Type'],
                'body' => $finished->log->body($body),
            ]);
            $finished->log->close();
            return;
        }

        if (!$finished->headersCommitted) {
            if ($status >= 300) {
                $body = BaiProtocol::forwardResponse($finished->connection, $response, $finished->route['client_alias']);
                $finished->log->event('downstream.response', [
                    'status' => $status,
                    'content_type' => BaiProtocol::upstreamHeaders($response)['Content-Type'],
                    'body' => $finished->log->body($body),
                ]);
            } else {
                $finished->connection->send(BaiProtocol::errorResponse(502, 'B.AI returned an unusable stream response.'));
                $finished->log->event('downstream.response', [
                    'status' => 502,
                    'body' => $finished->log->body('B.AI returned an unusable stream response.'),
                ]);
            }
            $finished->log->close();
            return;
        }

        if ($finished->streamBytes === 0 && $finished->pendingSse === '') {
            $finished->connection->send(new Chunk(BaiProtocol::streamError('B.AI returned an empty stream.')));
            $finished->connection->send(new Chunk(''));
            $finished->log->event('downstream.stream_complete', ['result' => 'empty_stream']);
            $finished->log->close();
            return;
        }

        $tail = BaiProtocol::rewriteSse(
            $finished->pendingSse,
            "\n\n",
            $finished->sseState,
            $finished->route['client_alias'],
        );
        if ($tail !== '') {
            $finished->connection->send(new Chunk($tail));
        }
        $repair = BaiProtocol::finishSse($finished->sseState, (bool) ($finished->model['repair_sse'] ?? false));
        if ($repair !== '') {
            $finished->connection->send(new Chunk($repair));
            $finished->log->event('downstream.stream_repaired');
        }
        $finished->connection->send(new Chunk(''));
        $finished->log->event('downstream.stream_complete', ['result' => 'ok']);
        $finished->log->close();
    }

    private function handleUpstreamError(RequestSession $session, int $attempt, mixed $error): void
    {
        $finished = $this->completeAttempt($session->id, $attempt);
        if ($finished === null) {
            return;
        }

        $finished->log->event('upstream.error', [
            'message' => $error instanceof Throwable ? $error->getMessage() : 'B.AI connection failed.',
        ]);
        if (BaiProtocol::connectionClosed($finished->connection)) {
            $this->counters['cancelled']++;
            $finished->log->event('downstream.disconnected', ['state' => 'in_flight']);
            $finished->log->close();
            return;
        }
        if ($finished->stream && $finished->headersCommitted) {
            $finished->connection->send(new Chunk(BaiProtocol::streamError('B.AI connection failed.')));
            $finished->connection->send(new Chunk(''));
            $finished->log->event('downstream.stream_complete', ['result' => 'upstream_error']);
            $finished->log->close();
            return;
        }

        $finished->connection->send(BaiProtocol::errorResponse(502, 'B.AI connection failed.'));
        $finished->log->event('downstream.response', [
            'status' => 502,
            'body' => $finished->log->body('B.AI connection failed.'),
        ]);
        $finished->log->close();
    }

    private function retry(RequestSession $session, int $attempt, object $response): void
    {
        if (!$this->isCurrentAttempt($session, $attempt)) {
            return;
        }
        if (BaiProtocol::connectionClosed($session->connection)) {
            $finished = $this->completeAttempt($session->id, $attempt);
            if ($finished !== null) {
                $this->counters['cancelled']++;
                $finished->log->event('downstream.disconnected', ['state' => 'retry']);
                $finished->log->close();
            }
            return;
        }

        $session->retries++;
        $this->counters['rate_limited']++;
        $this->clean = 0;
        $this->window = max(1, intdiv($this->window + 1, 2));

        $exponential = min(
            $this->config->retryMaxDelay,
            ($this->config->retryBaseMs / 1000) * (2 ** ($session->retries - 1)),
        );
        $jitterMin = (int) round($exponential * 500);
        $jitterMax = (int) round($exponential * 1000);
        $delay = max(
            BaiProtocol::retryAfterSeconds($response->getHeaderLine('Retry-After')) ?? 0.0,
            random_int($jitterMin, max($jitterMin, $jitterMax)) / 1000,
        );
        $this->cooldownUntil = max($this->cooldownUntil, microtime(true) + $delay);

        fwrite(STDOUT, sprintf(
            "[B.AI queue] 429: request=%d retry=%d delay=%.3fs window=%d\n",
            $session->id,
            $session->retries,
            $delay,
            $this->window,
        ));
        $session->log->event('upstream.rate_limited', [
            'attempt' => $attempt,
            'retry' => $session->retries,
            'delay_seconds' => $delay,
            'window' => $this->window,
            'retry_after' => $response->getHeaderLine('Retry-After'),
            'body' => $session->log->body((string) $response->getBody()),
        ]);

        if ($session->retries > $this->config->retryMax || microtime(true) + $delay > $session->deadline) {
            $finished = $this->completeAttempt($session->id, $attempt);
            if ($finished !== null) {
                $body = BaiProtocol::forwardResponse($finished->connection, $response, $finished->route['client_alias']);
                $finished->log->event('downstream.response', [
                    'status' => 429,
                    'body' => $finished->log->body($body),
                ]);
                $finished->log->close();
            }
            return;
        }

        // The same session (and therefore the same log file) survives every retry.
        $this->active--;
        $session->activeAttempt = 0;
        $session->state = 'retry_wait';
        $this->pending++;
        $this->queueBytes += strlen($session->upstreamBody);
        $this->counters['retries']++;
        $readyAt = $this->cooldownUntil;

        Timer::add(max(0.001, $readyAt - microtime(true)), function () use ($session): void {
            if (!isset($this->sessions[$session->id]) || $session->state !== 'retry_wait') {
                return;
            }
            $session->state = 'queued';
            $this->ready[] = $session->id;
            $this->schedulePump(microtime(true));
        }, [], false);
        $this->schedulePump($readyAt);
    }

    private function isCurrentAttempt(RequestSession $session, int $attempt): bool
    {
        return isset($this->sessions[$session->id])
            && $this->sessions[$session->id] === $session
            && $session->activeAttempt === $attempt;
    }
}

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== 'proxy.php') {
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

$config = ProxyConfig::fromFile(__DIR__ . '/.env');
if ($config->apiKey === '') {
    fwrite(STDERR, "[ERROR] BAI_API_KEY is missing in .env\n");
    exit(1);
}

$server = new BaiProxyServer(
    $config,
    ModelCatalog::fromFile(__DIR__ . DIRECTORY_SEPARATOR . $config->modelsFile),
    new SessionLogFactory(
        $config->logEnabled,
        __DIR__ . '/log',
        $config->logBodyEnabled,
        $config->logMaxBytes,
    ),
);
$server->run();
