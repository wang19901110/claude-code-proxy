<?php

declare(strict_types=1);

use Workerman\Http\Client;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

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
        'claude-haiku-4-5' => 'glm-5.3-flash',
        'claude-opus-4-6' => 'qwen3.8-flash',
        'claude-opus-4-5' => 'hy3',
        'claude-sonnet-4-5' => 'deepseek-v4-flash-vision-exp',
    ];
}

/** @return array<string, string> */
function legacy_model_map(): array
{
    // Keep previously advertised descriptive aliases working for direct callers,
    // but do not expose them to Desktop model discovery.
    return [
        'claude-opus_deepseek-v4-flash' => 'deepseek-v4-flash',
        'claude-opus_deepseek-v4-flash-vision-exp' => 'deepseek-v4-flash-vision-exp',
        'claude-opus_hy3' => 'hy3',
        'claude-opus_glm-5.3-flash' => 'glm-5.3-flash',
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

function upstream_model(string $requestedModel, array $config): ?string
{
    foreach (routing_model_map() as $alias => $upstream) {
        if (normalize_model($alias) === normalize_model($requestedModel)) {
            return $upstream;
        }
    }
    return $config['allow_unknown_models'] ? $config['default_upstream_model'] : null;
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
 * Keep the incomplete tail and only rewrite complete SSE records.
 */
function rewrite_sse_buffer(string &$pending, string $incoming, string $clientModel): string
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
    'default_upstream_model' => env_value($env, 'DEFAULT_UPSTREAM_MODEL', 'deepseek-v4-flash'),
    'allow_unknown_models' => filter_var(env_value($env, 'ALLOW_UNKNOWN_MODELS', 'true'), FILTER_VALIDATE_BOOL),
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

    if ($method === 'OPTIONS') {
        $connection->send(new Response(204, [
            'Access-Control-Allow-Origin' => 'http://127.0.0.1',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Api-Key, Anthropic-Version, Anthropic-Beta',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        ]));
        return;
    }

    if ($method === 'GET' && ($path === '/' || $path === '/health')) {
        $connection->send(json_response(200, [
            'status' => 'ok',
            'upstream' => 'https://api.b.ai/v1/messages',
            'models' => model_map(),
            'default_upstream_model' => $config['default_upstream_model'],
        ]));
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
        return;
    }

    if ($method !== 'POST' || !in_array($path, ['/v1/messages', '/v1/messages/count_tokens'], true)) {
        $connection->send(error_response(404, 'not_found_error', 'Only /v1/messages, /v1/messages/count_tokens, /v1/models, and /health are available.'));
        return;
    }

    $payload = json_decode($request->rawBody(), true);
    if (!is_array($payload)) {
        $connection->send(error_response(400, 'invalid_request_error', 'The request body must be valid JSON.'));
        return;
    }

    if ($path === '/v1/messages/count_tokens') {
        $connection->send(json_response(200, ['input_tokens' => count_tokens_locally($payload)]));
        return;
    }

    if ($config['bai_api_key'] === '') {
        $connection->send(error_response(500, 'api_error', 'BAI_API_KEY is not configured in the local .env file.'));
        return;
    }

    $requestedModel = (string) ($payload['model'] ?? '');
    if ($requestedModel === '') {
        $connection->send(error_response(400, 'invalid_request_error', 'model is required.'));
        return;
    }
    $upstreamModel = upstream_model($requestedModel, $config);
    if ($upstreamModel === null) {
        $connection->send(error_response(400, 'invalid_request_error', 'This model alias is not mapped by the local proxy.'));
        return;
    }

    $clientModel = requested_model_alias($requestedModel);
    $payload['model'] = $upstreamModel;

    // Claude Desktop's gateway health check may use max_tokens=1 or 2. B.AI
    // requires a value greater than 2, so make only these probe-sized requests
    // valid upstream. Normal user-selected limits pass through unchanged.
    $requestedMaxTokens = isset($payload['max_tokens']) ? (int) $payload['max_tokens'] : 0;
    if ($requestedMaxTokens <= 2) {
        $payload['max_tokens'] = 16;
    }

    $stream = (bool) ($payload['stream'] ?? false);
    $requestBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($requestBody === false) {
        $connection->send(error_response(400, 'invalid_request_error', 'Unable to encode the request body.'));
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
        $http->request('https://api.b.ai/v1/messages', [
            'method' => 'POST',
            'headers' => $headers,
            'data' => $requestBody,
            'progress' => static function (string $buffer) use ($connection, &$pending, $clientModel): void {
                $rewritten = rewrite_sse_buffer($pending, $buffer, $clientModel);
                if ($rewritten !== '') {
                    $connection->send(new Chunk($rewritten));
                }
            },
            'success' => static function ($response) use ($connection, &$pending, $clientModel): void {
                if ($pending !== '') {
                    $connection->send(new Chunk(rewrite_json_model($pending, $clientModel)));
                }
                $connection->send(new Chunk(''));
            },
            'error' => static function (\Throwable $exception) use ($connection): void {
                $event = 'event: error' . "\n" . 'data: ' . json_encode([
                    'type' => 'error',
                    'error' => ['type' => 'api_error', 'message' => 'Upstream connection failed: ' . $exception->getMessage()],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                $connection->send(new Chunk($event));
                $connection->send(new Chunk(''));
            },
        ]);
        return;
    }

    $http->request('https://api.b.ai/v1/messages', [
        'method' => 'POST',
        'headers' => $headers,
        'data' => $requestBody,
        'success' => static function ($response) use ($connection, $clientModel): void {
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $contentType = $response->getHeaderLine('Content-Type') ?: 'application/json; charset=utf-8';
            $connection->send(new Response($status, ['Content-Type' => $contentType], rewrite_json_model($body, $clientModel)));
        },
        'error' => static function (\Throwable $exception) use ($connection): void {
            $connection->send(error_response(502, 'api_error', 'Upstream connection failed: ' . $exception->getMessage()));
        },
    ]);
};

fwrite(STDOUT, sprintf("B.AI Claude Desktop proxy listening on http://%s:%d\n", $config['host'], $config['port']));
Worker::runAll();
