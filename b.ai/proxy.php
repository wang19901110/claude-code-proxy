<?php

declare(strict_types=1);

use Workerman\Http\Client;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Claude Desktop -> B.AI loopback proxy.
 *
 * It keeps the Anthropic Messages protocol intact but replaces the model ID before
 * forwarding it to B.AI. The model IDs in MODEL_MAP are only local Desktop aliases.
 */

function load_env(string $path): array
{
    $env = [];
    if (!is_file($path)) {
        return $env;
    }

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

function env_value(array $env, string $name, string $default = ''): string
{
    $system = getenv($name);
    if ($system !== false && $system !== '') {
        return $system;
    }
    return $env[$name] ?? $default;
}

function normalize_model(string $model): string
{
    return strtolower(str_replace(['.', '_'], '-', trim($model)));
}

/** @return array<string, string> */
function model_map(): array
{
    return [
        // Claude Desktop only accepts these known Claude-style model IDs in its
        // discovery picker. Their upstream destination remains visible at /health.
        'claude-sonnet-4-6' => 'deepseek-v4-flash',
        'claude-opus-4-6' => 'qwen3.8-flash',
        'claude-opus-4-5' => 'hy3',
    ];
}

/** @return array<string, int> */
function model_max_tokens(): array
{
    return [
        'deepseek-v4-flash' => 32000,
        'qwen3.8-flash' => 64000,
        'hy3' => 32000,
    ];
}

/** @return array<string, string> */
function legacy_model_map(): array
{
    // Keep previously advertised descriptive aliases working for direct callers,
        // but do not expose them to Desktop model discovery.
        return [
        'claude-opus_deepseek-v4-flash' => 'deepseek-v4-flash',
        'claude-opus_hy3' => 'hy3',
        'claude-opus_qwen3.8-flash' => 'qwen3.8-flash',
    ];
}

/** @return array<string, string> */
function routing_model_map(): array
{
    return model_map() + legacy_model_map();
}

function requested_model_alias(string $model): string
{
    foreach (array_keys(routing_model_map()) as $alias) {
        if (normalize_model($alias) === normalize_model($model)) {
            return $alias;
        }
    }
    return $model;
}

function upstream_model(string $requestedModel): ?string
{
    foreach (routing_model_map() as $alias => $upstream) {
        if (normalize_model($alias) === normalize_model($requestedModel)) {
            return $upstream;
        }
    }
    return null;
}

function json_response(int $status, array $body): Response
{
    return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'], json_encode(
        $body,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
}

function error_response(int $status, string $type, string $message): Response
{
    return json_response($status, ['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]);
}

function proxy_log(string $level, string $message, array $context = []): void
{
    $entry = [
        'timestamp' => date(DATE_ATOM),
        'level' => $level,
        'message' => $message,
        'context' => $context,
    ];
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line !== false) {
        @file_put_contents(__DIR__ . '/workerman.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    proxy_console_log($level, $message, $context);
}

function reset_proxy_log(): void
{
    file_put_contents(__DIR__ . '/workerman.log', '', LOCK_EX);
}

function proxy_console_log(string $level, string $message, array $context): void
{
    // Claude Desktop polls count_tokens frequently. Keep it in the JSON log but
    // do not flood the interactive console with those inexpensive local probes.
    if (($context['path'] ?? '') === '/v1/messages/count_tokens' || ($context['endpoint'] ?? '') === 'count_tokens') {
        return;
    }

    $time = date('H:i:s');
    $requestId = $context['request_id'] ?? '-';
    $line = null;

    if ($message === 'proxy_started') {
        $line = sprintf(
            '[%s] [INFO] Proxy ready on http://%s:%d with %d models',
            $time,
            $context['host'] ?? '127.0.0.1',
            $context['port'] ?? 0,
            $context['model_count'] ?? 0,
        );
    } elseif ($message === 'request_received' && in_array($context['path'] ?? '', ['/v1/messages', '/v1/models'], true)) {
        $line = sprintf('[%s] [REQUEST] %s %s id=%s', $time, $context['method'] ?? '?', $context['path'], $requestId);
    } elseif ($message === 'forwarding_request') {
        $line = sprintf(
            '[%s] [FORWARD] id=%s %s -> %s %s max_tokens=%s',
            $time,
            $requestId,
            $context['requested_model'] ?? '?',
            $context['upstream_model'] ?? '?',
            ($context['stream'] ?? false) ? 'SSE' : 'JSON',
            $context['max_tokens'] ?? '?',
        );
    } elseif (in_array($message, ['upstream_response', 'upstream_error'], true)) {
        $bytes = ($context['stream'] ?? false)
            ? ($context['stream_bytes'] ?? 0)
            : ($context['response_bytes'] ?? 0);
        $suffix = !empty($context['sse_repaired']) ? ' repaired_sse=yes' : '';
        $error = $context['error_type'] ?? ($context['error'] ?? '');
        $line = sprintf(
            '[%s] [%s] id=%s model=%s status=%s latency=%sms bytes=%s%s%s',
            $time,
            $message === 'upstream_error' || $level === 'error' ? 'ERROR' : strtoupper($level),
            $requestId,
            $context['upstream_model'] ?? '?',
            $context['status'] ?? '-',
            $context['latency_ms'] ?? '-',
            $bytes,
            $suffix,
            $error === '' ? '' : ' error=' . $error,
        );
    } elseif ($message === 'request_completed' && ($context['status'] ?? 200) >= 400) {
        $line = sprintf('[%s] [WARN] id=%s request completed with HTTP %s', $time, $requestId, $context['status']);
    }

    if ($line !== null) {
        fwrite(STDOUT, $line . PHP_EOL);
    }
}

function replace_model_value(mixed $value, string $clientModel): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    foreach ($value as $key => $item) {
        if ($key === 'model' && is_string($item)) {
            $value[$key] = $clientModel;
            continue;
        }
        $value[$key] = replace_model_value($item, $clientModel);
    }
    return $value;
}

function rewrite_json_model(string $json, string $clientModel): string
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $json;
    }
    return json_encode(
        replace_model_value($decoded, $clientModel),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

/**
 * Client progress callbacks may split a single SSE event across byte chunks.
 * Keep the incomplete tail, rewrite complete SSE records, and track whether
 * upstream emitted the terminal Anthropic events expected by Claude Desktop.
 */
function track_sse_payload(string $payload, array &$state): void
{
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        return;
    }

    $type = $decoded['type'] ?? '';
    if ($type === 'message_start') {
        $state['message_started'] = true;
        return;
    }
    if ($type === 'message_stop') {
        $state['message_stopped'] = true;
        return;
    }

    $index = $decoded['index'] ?? null;
    if (!is_int($index) && !is_numeric($index)) {
        return;
    }
    $key = (string) $index;
    if ($type === 'content_block_start') {
        $state['open_blocks'][$key] = (int) $index;
    } elseif ($type === 'content_block_stop') {
        unset($state['open_blocks'][$key]);
    }
}

function complete_sse_stream(array &$state): string
{
    if (!$state['message_started'] || $state['message_stopped']) {
        return '';
    }

    $output = '';
    foreach (array_reverse(array_values($state['open_blocks'])) as $index) {
        $output .= 'event: content_block_stop' . "\n" . 'data: ' . json_encode([
            'type' => 'content_block_stop',
            'index' => $index,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }
    $output .= 'event: message_delta' . "\n" . 'data: ' . json_encode([
        'type' => 'message_delta',
        'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null],
        'usage' => ['output_tokens' => 0],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    $output .= 'event: message_stop' . "\n" . 'data: ' . json_encode([
        'type' => 'message_stop',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

    $state['open_blocks'] = [];
    $state['message_stopped'] = true;
    return $output;
}

function rewrite_sse_buffer(string &$pending, string $incoming, string $clientModel, array &$state): string
{
    $pending .= $incoming;
    $output = '';

    while (preg_match('/\r?\n\r?\n/', $pending, $match, PREG_OFFSET_CAPTURE)) {
        $separator = $match[0][0];
        $position = $match[0][1];
        $record = substr($pending, 0, $position);
        $pending = substr($pending, $position + strlen($separator));

        $lines = preg_split('/\r?\n/', $record) ?: [];
        foreach ($lines as &$line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $prefix = substr($line, 0, 5);
            $payload = ltrim(substr($line, 5));
            if ($payload !== '' && $payload !== '[DONE]') {
                track_sse_payload($payload, $state);
                $line = $prefix . ' ' . rewrite_json_model($payload, $clientModel);
            }
        }
        unset($line);
        $output .= implode("\n", $lines) . "\n\n";
    }
    return $output;
}

function count_tokens_locally(array $payload): int
{
    // Claude Desktop only needs this endpoint for an estimate. B.AI does not expose
    // Anthropic's count-tokens endpoint, so avoid an unnecessary upstream request.
    $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $characters = function_exists('mb_strlen') ? mb_strlen($serialized, 'UTF-8') : strlen($serialized);
    return max(1, (int) ceil($characters / 4));
}

$env = load_env(__DIR__ . '/.env');
$config = [
    'bai_api_key' => env_value($env, 'BAI_API_KEY'),
    'host' => env_value($env, 'PROXY_HOST', '127.0.0.1'),
    'port' => (int) env_value($env, 'PROXY_PORT', '8787'),
    'upstream_timeout' => (int) env_value($env, 'UPSTREAM_TIMEOUT', '180'),
];

if ($config['host'] !== '127.0.0.1' && $config['host'] !== '::1') {
    fwrite(STDERR, "For safety, PROXY_HOST must be 127.0.0.1 or ::1.\n");
    exit(1);
}

$worker = new Worker(sprintf('http://%s:%d', $config['host'], $config['port']));
$worker->name = 'bai-claude-proxy';
$worker->count = 1;
$worker->onWorkerStart = static function () use ($config): void {
    $GLOBALS['bai_http_client'] = new Client([
        'connect_timeout' => 30,
        'timeout' => max(30, $config['upstream_timeout']),
        'keepalive_timeout' => 30,
    ]);
};

$worker->onMessage = static function ($connection, Request $request) use ($config): void {
    $path = $request->path();
    $method = strtoupper($request->method());
    $requestId = bin2hex(random_bytes(8));
    $startedAt = microtime(true);

    proxy_log('info', 'request_received', [
        'request_id' => $requestId,
        'method' => $method,
        'path' => $path,
    ]);

    if ($method === 'OPTIONS') {
        $connection->send(new Response(204, [
            'Access-Control-Allow-Origin' => 'http://127.0.0.1',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Api-Key, Anthropic-Version, Anthropic-Beta',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        ]));
        proxy_log('info', 'request_completed', [
            'request_id' => $requestId,
            'status' => 204,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        return;
    }

    if ($method === 'GET' && ($path === '/' || $path === '/health')) {
        $connection->send(json_response(200, [
            'status' => 'ok',
            'upstream' => 'https://api.b.ai/v1/messages',
            'models' => model_map(),
        ]));
        proxy_log('info', 'request_completed', [
            'request_id' => $requestId,
            'status' => 200,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        return;
    }

    if (in_array($method, ['GET', 'POST', 'HEAD'], true) && $path === '/api/hello') {
        // Claude Code Desktop probes this endpoint with HEAD before normal API calls.
        // HEAD must confirm availability without sending a response body.
        $connection->send($method === 'HEAD'
            ? new Response(200, ['Content-Type' => 'application/json; charset=utf-8'])
            : json_response(200, [
                'status' => 'ok',
                'service' => 'local-bai-proxy',
                'model_count' => count(model_map()),
            ])
        );
        proxy_log('info', 'request_completed', [
            'request_id' => $requestId,
            'status' => 200,
            'endpoint' => 'api_hello',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        return;
    }

    if ($method === 'GET' && $path === '/v1/models') {
        $models = [];
        foreach (model_map() as $alias => $upstream) {
            $models[] = [
                'id' => $alias,
                'object' => 'model',
                'owned_by' => 'local-bai-proxy',
                // Claude Code gateway discovery uses this for the picker label
                // while retaining the standards-compliant ID for validation.
                'display_name' => $upstream,
                'upstream_model' => $upstream,
            ];
        }
        $connection->send(json_response(200, ['object' => 'list', 'data' => $models]));
        proxy_log('info', 'request_completed', [
            'request_id' => $requestId,
            'status' => 200,
            'model_count' => count($models),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        return;
    }

    if ($method !== 'POST' || !in_array($path, ['/v1/messages', '/v1/messages/count_tokens'], true)) {
        $connection->send(error_response(404, 'not_found_error', 'Only /v1/messages, /v1/messages/count_tokens, /v1/models, /health, and /api/hello are available.'));
        proxy_log('warning', 'request_completed', [
            'request_id' => $requestId,
            'status' => 404,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        return;
    }

    $payload = json_decode($request->rawBody(), true);
    if (!is_array($payload)) {
        $connection->send(error_response(400, 'invalid_request_error', 'The request body must be valid JSON.'));
        proxy_log('warning', 'request_completed', [
            'request_id' => $requestId,
            'status' => 400,
            'error_type' => 'invalid_json',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        return;
    }

    if ($path === '/v1/messages/count_tokens') {
        $connection->send(json_response(200, ['input_tokens' => count_tokens_locally($payload)]));
        proxy_log('info', 'request_completed', [
            'request_id' => $requestId,
            'status' => 200,
            'endpoint' => 'count_tokens',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        return;
    }

    if ($config['bai_api_key'] === '') {
        $connection->send(error_response(500, 'api_error', 'BAI_API_KEY is not configured in the local .env file.'));
        proxy_log('error', 'request_completed', [
            'request_id' => $requestId,
            'status' => 500,
            'error_type' => 'missing_api_key',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        return;
    }

    $requestedModel = (string) ($payload['model'] ?? '');
    if ($requestedModel === '') {
        $connection->send(error_response(400, 'invalid_request_error', 'model is required.'));
        proxy_log('warning', 'request_completed', [
            'request_id' => $requestId,
            'status' => 400,
            'error_type' => 'missing_model',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        return;
    }
    $upstreamModel = upstream_model($requestedModel);
    if ($upstreamModel === null) {
        $connection->send(error_response(400, 'invalid_request_error', 'This model alias is not mapped by the local proxy.'));
        proxy_log('warning', 'request_completed', [
            'request_id' => $requestId,
            'status' => 400,
            'requested_model' => $requestedModel,
            'error_type' => 'unmapped_model',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        return;
    }

    $clientModel = requested_model_alias($requestedModel);
    $payload['model'] = $upstreamModel;

    // Claude Desktop's gateway health check may use max_tokens=1 or 2. B.AI
    // requires a value greater than 2, so retain a small probe budget in that case.
    // Normal requests use the explicit per-model limits above, independent of the
    // value supplied by the Desktop client.
    $requestedMaxTokens = isset($payload['max_tokens']) ? (int) $payload['max_tokens'] : 0;
    if ($requestedMaxTokens <= 2) {
        $payload['max_tokens'] = 16;
    } else {
        $payload['max_tokens'] = model_max_tokens()[$upstreamModel];
    }

    $stream = (bool) ($payload['stream'] ?? false);
    proxy_log('info', 'forwarding_request', [
        'request_id' => $requestId,
        'requested_model' => $requestedModel,
        'upstream_model' => $upstreamModel,
        'stream' => $stream,
        'requested_max_tokens' => $requestedMaxTokens,
        'max_tokens' => $payload['max_tokens'] ?? null,
    ]);
    $requestBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($requestBody === false) {
        $connection->send(error_response(400, 'invalid_request_error', 'Unable to encode the request body.'));
        proxy_log('warning', 'request_completed', [
            'request_id' => $requestId,
            'status' => 400,
            'requested_model' => $requestedModel,
            'error_type' => 'json_encode_failed',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        return;
    }

    $headers = [
        'Authorization' => 'Bearer ' . $config['bai_api_key'],
        'Content-Type' => 'application/json',
        'Accept' => $stream ? 'text/event-stream' : 'application/json',
        'anthropic-version' => $request->header('anthropic-version', '2023-06-01'),
    ];
    $beta = $request->header('anthropic-beta', '');
    if ($beta !== '') {
        $headers['anthropic-beta'] = $beta;
    }

    /** @var Client $http */
    $http = $GLOBALS['bai_http_client'];

    if ($stream) {
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
        $sseState = [
            'message_started' => false,
            'message_stopped' => false,
            'open_blocks' => [],
        ];
        $http->request('https://api.b.ai/v1/messages', [
            'method' => 'POST',
            'headers' => $headers,
            'data' => $requestBody,
            'progress' => static function (string $buffer) use ($connection, &$pending, $clientModel, &$streamBytes, &$streamChunks, &$sseState): void {
                $streamBytes += strlen($buffer);
                $streamChunks++;
                $rewritten = rewrite_sse_buffer($pending, $buffer, $clientModel, $sseState);
                if ($rewritten !== '') {
                    $connection->send(new Chunk($rewritten));
                }
            },
            'success' => static function ($response) use ($connection, &$pending, $clientModel, $requestId, $requestedModel, $upstreamModel, $startedAt, &$streamBytes, &$streamChunks, &$sseState): void {
                $status = $response->getStatusCode();
                if ($status >= 400 || ($streamBytes === 0 && $pending === '')) {
                    $message = $status >= 400
                        ? 'Upstream returned HTTP ' . $status . '.'
                        : 'Upstream returned an empty stream.';
                    $event = 'event: error' . "\n" . 'data: ' . json_encode([
                        'type' => 'error',
                        'error' => ['type' => 'api_error', 'message' => $message],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                    $connection->send(new Chunk($event));
                    $connection->send(new Chunk(''));
                    proxy_log($status >= 400 ? 'warning' : 'error', 'upstream_response', [
                        'request_id' => $requestId,
                        'requested_model' => $requestedModel,
                        'upstream_model' => $upstreamModel,
                        'stream' => true,
                        'status' => $status,
                        'stream_bytes' => $streamBytes,
                        'stream_chunks' => $streamChunks,
                        'error_type' => $status >= 400 ? 'upstream_http_error' : 'empty_stream',
                        'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ]);
                    return;
                }
                if ($pending !== '') {
                    $connection->send(new Chunk(rewrite_json_model($pending, $clientModel)));
                }
                $repaired = complete_sse_stream($sseState);
                if ($repaired !== '') {
                    $connection->send(new Chunk($repaired));
                }
                $connection->send(new Chunk(''));
                proxy_log('info', 'upstream_response', [
                    'request_id' => $requestId,
                    'requested_model' => $requestedModel,
                    'upstream_model' => $upstreamModel,
                    'stream' => true,
                    'status' => $status,
                    'stream_bytes' => $streamBytes,
                    'stream_chunks' => $streamChunks,
                    'sse_repaired' => $repaired !== '',
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
            },
            'error' => static function (\Throwable $exception) use ($connection, $requestId, $requestedModel, $upstreamModel, $startedAt, &$streamBytes, &$streamChunks): void {
                $event = 'event: error' . "\n" . 'data: ' . json_encode([
                    'type' => 'error',
                    'error' => ['type' => 'api_error', 'message' => 'Upstream connection failed: ' . $exception->getMessage()],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                $connection->send(new Chunk($event));
                $connection->send(new Chunk(''));
                proxy_log('error', 'upstream_error', [
                    'request_id' => $requestId,
                    'requested_model' => $requestedModel,
                    'upstream_model' => $upstreamModel,
                    'stream' => true,
                    'stream_bytes' => $streamBytes,
                    'stream_chunks' => $streamChunks,
                    'error' => $exception->getMessage(),
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
            },
        ]);
        return;
    }

    $http->request('https://api.b.ai/v1/messages', [
        'method' => 'POST',
        'headers' => $headers,
        'data' => $requestBody,
        'success' => static function ($response) use ($connection, $clientModel, $requestId, $requestedModel, $upstreamModel, $startedAt): void {
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $contentType = $response->getHeaderLine('Content-Type') ?: 'application/json; charset=utf-8';
            $connection->send(new Response($status, ['Content-Type' => $contentType], rewrite_json_model($body, $clientModel)));
            proxy_log($status >= 400 ? 'warning' : 'info', 'upstream_response', [
                'request_id' => $requestId,
                'requested_model' => $requestedModel,
                'upstream_model' => $upstreamModel,
                'stream' => false,
                'status' => $status,
                'response_bytes' => strlen($body),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        },
        'error' => static function (\Throwable $exception) use ($connection, $requestId, $requestedModel, $upstreamModel, $startedAt): void {
            $connection->send(error_response(502, 'api_error', 'Upstream connection failed: ' . $exception->getMessage()));
            proxy_log('error', 'upstream_error', [
                'request_id' => $requestId,
                'requested_model' => $requestedModel,
                'upstream_model' => $upstreamModel,
                'stream' => false,
                'error' => $exception->getMessage(),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        },
    ]);
};

reset_proxy_log();
fwrite(STDOUT, sprintf("B.AI Claude Desktop proxy listening on http://%s:%d\n", $config['host'], $config['port']));
proxy_log('info', 'proxy_started', [
    'host' => $config['host'],
    'port' => $config['port'],
    'model_count' => count(model_map()),
    'upstream_timeout' => $config['upstream_timeout'],
]);
Worker::runAll();
