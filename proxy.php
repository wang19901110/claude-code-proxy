<?php

declare(strict_types=1);

use Workerman\Http\Client;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

const HOST = '127.0.0.1';
const PORT = 8787;

$models = [
    [
        'alias' => 'claude-sonnet-1-1',
        'upstream' => 'deepseek-v4-flash',
        'name' => 'deepseek-v4-flash',
        'max_tokens' => 32000,
    ],
    [
        'alias' => 'claude-sonnet-1-2',
        'upstream' => 'qwen3.8-flash',
        'name' => 'qwen3.8-flash',
        'max_tokens' => 64000,
    ],
    [
        'alias' => 'claude-sonnet-1-3',
        'upstream' => 'hy3',
        'name' => 'hy3',
        'max_tokens' => 32000,
        'repair_sse' => true,
    ],
    [
        'alias' => 'claude-sonnet-1-4',
        'upstream' => 'glm-5.3-flash',
        'name' => 'glm-5.3-flash',
        'max_tokens' => 131072,
    ],
];

function loadEnv(string $path): array
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

function findModel(array $models, string $requested): ?array
{
    $requested = trim($requested);
    foreach ($models as $model) {
        if ($model['alias'] === $requested) {
            return ['model' => $model, 'client_alias' => $model['alias']];
        }
    }
    return null;
}

function jsonResponse(int $status, array $body): Response
{
    return new Response(
        $status,
        ['Content-Type' => 'application/json; charset=utf-8'],
        json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    );
}

function errorResponse(int $status, string $message): Response
{
    return jsonResponse($status, [
        'type' => 'error',
        'error' => ['type' => 'api_error', 'message' => $message],
    ]);
}

function upstreamResponseHeaders(object $response): array
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

function forwardUpstreamResponse($connection, object $response, string $clientModel): string
{
    $body = rewriteJson((string) $response->getBody(), $clientModel);
    $connection->send(new Response($response->getStatusCode(), upstreamResponseHeaders($response), $body));
    return $body;
}

function rewriteModel(mixed $value, string $clientModel): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $item) {
        $value[$key] = $key === 'model' && is_string($item)
            ? $clientModel
            : rewriteModel($item, $clientModel);
    }
    return $value;
}

function rewriteJson(string $body, string $clientModel): string
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return $body;
    }
    return json_encode(rewriteModel($decoded, $clientModel), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ?: $body;
}

function rewriteSse(string &$pending, string $incoming, array &$state, string $clientModel): string
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
            $event = json_decode($data, true);
            if (!is_array($event)) {
                continue;
            }

            $type = $event['type'] ?? '';
            if ($type === 'message_start') {
                $state['started'] = true;
            } elseif ($type === 'message_stop') {
                $state['stopped'] = true;
            } elseif (isset($event['index']) && is_numeric($event['index'])) {
                $index = (int) $event['index'];
                if ($type === 'content_block_start') {
                    $state['blocks'][$index] = $index;
                } elseif ($type === 'content_block_stop') {
                    unset($state['blocks'][$index]);
                }
            }

            $line = 'data: ' . (json_encode(
                rewriteModel($event, $clientModel),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: $data);
        }
        unset($line);
        $output .= implode("\n", $lines) . "\n\n";
    }

    return $output;
}

function finishSse(array &$state, bool $repair): string
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

function streamError(string $message): string
{
    return "event: error\n" . 'data: ' . json_encode([
        'type' => 'error',
        'error' => ['type' => 'api_error', 'message' => $message],
    ]) . "\n\n";
}

function tokenCount(mixed $payload): int
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $length = function_exists('mb_strlen') ? mb_strlen($json, 'UTF-8') : strlen($json);
    return max(1, (int) ceil($length / 4));
}

function envInt(array $env, string $key, int $default, int $min, int $max = PHP_INT_MAX): int
{
    $value = filter_var($env[$key] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, $value));
}

function envBool(array $env, string $key, bool $default): bool
{
    $value = strtolower(trim((string) ($env[$key] ?? '')));
    return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function logBody(string $body, bool $includeBody, int $maxBytes): array
{
    $result = ['bytes' => strlen($body)];
    if (!$includeBody) {
        return $result;
    }
    if (strlen($body) <= $maxBytes) {
        $json = json_decode($body);
        if (json_last_error() === JSON_ERROR_NONE) {
            $result['json'] = $json;
            return $result;
        }
    }

    $result['text'] = substr($body, 0, $maxBytes);
    if (strlen($body) > $maxBytes) {
        $result['truncated'] = true;
    }
    return $result;
}

function writeProxyLog(string $path, string $event, array $context): void
{
    $entry = json_encode([
        'time' => gmdate('c'),
        'event' => $event,
        ...$context,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($entry !== false) {
        $prefix = is_file($path) && filesize($path) > 0 ? PHP_EOL : '';
        @file_put_contents($path, $prefix . $entry . PHP_EOL . str_repeat('=', 88) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function retryAfterSeconds(string $value): ?float
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

function connectionClosed(mixed $connection): bool
{
    return $connection instanceof TcpConnection
        && $connection->getStatus() === TcpConnection::STATUS_CLOSED;
}

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== 'proxy.php') {
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

$env = loadEnv(__DIR__ . '/.env');
$apiKey = trim($env['BAI_API_KEY'] ?? '');
if ($apiKey === '') {
    fwrite(STDERR, "[ERROR] BAI_API_KEY is missing in .env\n");
    exit(1);
}

$upstreamTimeout = envInt($env, 'UPSTREAM_TIMEOUT', 180, 30);
$initialConcurrency = envInt($env, 'BAI_INITIAL_CONCURRENCY', 2, 1, 64);
$maxConcurrency = envInt($env, 'BAI_MAX_CONCURRENCY', 8, $initialConcurrency, 64);
$queueCapacity = envInt($env, 'BAI_QUEUE_CAPACITY', 64, 1, 10000);
$queueMaxWait = envInt($env, 'BAI_QUEUE_MAX_WAIT', 120, 1, 3600);
$queueMaxBytes = envInt($env, 'BAI_QUEUE_MAX_BYTES', 67108864, 1024, 268435456);
$retryMax = envInt($env, 'BAI_RETRY_MAX', 3, 0, 10);
$retryBaseMs = envInt($env, 'BAI_RETRY_BASE_MS', 1000, 100, 60000);
$retryMaxDelay = envInt($env, 'BAI_RETRY_MAX_DELAY', 30, 1, 300);
$logBodyEnabled = envBool($env, 'BAI_LOG_BODY', true);
$logMaxBytes = envInt($env, 'BAI_LOG_MAX_BYTES', 65536, 0, 1048576);
$logPath = __DIR__ . '/proxy.log';
$log = static function (string $event, array $context = []) use ($logPath): void {
    writeProxyLog($logPath, $event, $context);
};
$client = null;
$scheduler = [
    'jobs' => [],
    'ready' => [],
    'active' => 0,
    'pending' => 0,
    'queue_bytes' => 0,
    'window' => $initialConcurrency,
    'clean' => 0,
    'cooldown_until' => 0.0,
    'wake_at' => 0.0,
    'wake_timer' => null,
    'next_id' => 0,
    'counters' => ['completed' => 0, 'rate_limited' => 0, 'retries' => 0, 'rejected' => 0, 'expired' => 0, 'cancelled' => 0],
];

$worker = new Worker(sprintf('http://%s:%d', HOST, PORT));
$worker->name = 'bai-proxy';
$worker->count = 1;
$schedulePump = null;
$pump = null;
$discard = null;
$complete = null;
$retry = null;
$dispatch = null;
$enqueue = null;

$schedulePump = static function (float $when) use (&$scheduler, &$pump): void {
    if ($scheduler['wake_at'] > 0 && $scheduler['wake_at'] <= $when) {
        return;
    }
    if ($scheduler['wake_timer'] !== null) {
        Timer::del($scheduler['wake_timer']);
    }
    $scheduler['wake_at'] = $when;
    $scheduler['wake_timer'] = Timer::add(max(0.001, $when - microtime(true)), static function () use (&$scheduler, &$pump): void {
        $scheduler['wake_at'] = 0.0;
        $scheduler['wake_timer'] = null;
        $pump();
    }, [], false);
};

$discard = static function (int $id) use (&$scheduler): void {
    if (!isset($scheduler['jobs'][$id])) {
        return;
    }
    $job = $scheduler['jobs'][$id];
    if ($job['state'] !== 'in_flight') {
        $scheduler['pending']--;
        $scheduler['queue_bytes'] -= strlen($job['body']);
    }
    unset($scheduler['jobs'][$id]);
};

$complete = static function (int $id, int $attempt) use (&$scheduler, &$pump): ?array {
    if (!isset($scheduler['jobs'][$id]) || $scheduler['jobs'][$id]['active_attempt'] !== $attempt) {
        return null;
    }
    $job = $scheduler['jobs'][$id];
    $scheduler['active']--;
    unset($scheduler['jobs'][$id]);
    $pump();
    return $job;
};

$retry = static function (int $id, int $attempt, $response) use (
    &$scheduler,
    &$complete,
    &$schedulePump,
    $retryMax,
    $retryBaseMs,
    $retryMaxDelay,
    $log,
    $logBodyEnabled,
    $logMaxBytes,
): void {
    if (!isset($scheduler['jobs'][$id]) || $scheduler['jobs'][$id]['active_attempt'] !== $attempt) {
        return;
    }

    $job = &$scheduler['jobs'][$id];
    if (connectionClosed($job['connection'])) {
        $complete($id, $attempt);
        $scheduler['counters']['cancelled']++;
        return;
    }
    $job['retries']++;
    $scheduler['counters']['rate_limited']++;
    $scheduler['clean'] = 0;
    $scheduler['window'] = max(1, intdiv($scheduler['window'] + 1, 2));

    $exponential = min($retryMaxDelay, ($retryBaseMs / 1000) * (2 ** ($job['retries'] - 1)));
    $jitterMin = (int) round($exponential * 500);
    $jitterMax = (int) round($exponential * 1000);
    $delay = max(
        retryAfterSeconds($response->getHeaderLine('Retry-After')) ?? 0.0,
        random_int($jitterMin, max($jitterMin, $jitterMax)) / 1000,
    );
    $scheduler['cooldown_until'] = max($scheduler['cooldown_until'], microtime(true) + $delay);
    fwrite(STDOUT, sprintf("[B.AI queue] 429: request=%d retry=%d delay=%.3fs window=%d\n", $id, $job['retries'], $delay, $scheduler['window']));
    $log('upstream.rate_limited', [
        'request' => $id,
        'retry' => $job['retries'],
        'delay_seconds' => $delay,
        'window' => $scheduler['window'],
        'retry_after' => $response->getHeaderLine('Retry-After'),
        'body' => logBody((string) $response->getBody(), $logBodyEnabled, $logMaxBytes),
    ]);

    if ($job['retries'] > $retryMax || microtime(true) + $delay > $job['deadline']) {
        $finished = $complete($id, $attempt);
        if ($finished !== null && !connectionClosed($finished['connection'])) {
            $downstreamBody = forwardUpstreamResponse($finished['connection'], $response, $finished['route']['client_alias']);
            $log('downstream.response', [
                'request' => $id,
                'status' => 429,
                'body' => logBody($downstreamBody, $logBodyEnabled, $logMaxBytes),
            ]);
        }
        return;
    }

    $scheduler['active']--;
    $job['active_attempt'] = 0;
    $job['state'] = 'backoff';
    $scheduler['pending']++;
    $scheduler['queue_bytes'] += strlen($job['body']);
    $scheduler['counters']['retries']++;
    $readyAt = $scheduler['cooldown_until'];
    Timer::add(max(0.001, $readyAt - microtime(true)), static function () use (&$scheduler, &$schedulePump, $id): void {
        if (!isset($scheduler['jobs'][$id]) || $scheduler['jobs'][$id]['state'] !== 'backoff') {
            return;
        }
        $scheduler['jobs'][$id]['state'] = 'queued';
        $scheduler['ready'][] = $id;
        $schedulePump(microtime(true));
    }, [], false);
    $schedulePump($readyAt);
};

$dispatch = static function (int $id) use (
    &$scheduler,
    &$client,
    &$complete,
    &$retry,
    $maxConcurrency,
    $log,
    $logBodyEnabled,
    $logMaxBytes,
): void {
    if (!isset($scheduler['jobs'][$id])) {
        return;
    }
    $job = &$scheduler['jobs'][$id];
    if (connectionClosed($job['connection'])) {
        unset($scheduler['jobs'][$id]);
        $scheduler['counters']['cancelled']++;
        return;
    }

    $job['state'] = 'in_flight';
    $job['active_attempt']++;
    $attempt = $job['active_attempt'];
    $scheduler['active']++;

    $options = [
        'method' => 'POST',
        'headers' => $job['headers'],
        'data' => $job['body'],
        'success' => static function ($response) use (
            &$scheduler,
            &$complete,
            &$retry,
            $id,
            $attempt,
            $maxConcurrency,
            $log,
            $logBodyEnabled,
            $logMaxBytes,
        ): void {
            if (!isset($scheduler['jobs'][$id]) || $scheduler['jobs'][$id]['active_attempt'] !== $attempt) {
                return;
            }
            $job = $scheduler['jobs'][$id];
            $status = $response->getStatusCode();
            if (!$job['stream'] || $status >= 300) {
                $log('upstream.response', [
                    'request' => $id,
                    'status' => $status,
                    'content_type' => $response->getHeaderLine('Content-Type'),
                    'retry_after' => $response->getHeaderLine('Retry-After'),
                    'body' => logBody((string) $response->getBody(), $logBodyEnabled, $logMaxBytes),
                ]);
            }
            if ($status === 429 && !$job['headers_committed']) {
                $retry($id, $attempt, $response);
                return;
            }

            $finished = $complete($id, $attempt);
            if ($finished === null || connectionClosed($finished['connection'])) {
                return;
            }
            $scheduler['counters']['completed']++;
            if ($status >= 200 && $status < 300) {
                $scheduler['clean']++;
                if ($scheduler['clean'] >= 8 && $scheduler['window'] < $maxConcurrency) {
                    $scheduler['window']++;
                    $scheduler['clean'] = 0;
                }
            }

            if (!$finished['stream']) {
                $headers = upstreamResponseHeaders($response);
                $downstreamBody = forwardUpstreamResponse($finished['connection'], $response, $finished['route']['client_alias']);
                $log('downstream.response', [
                    'request' => $id,
                    'status' => $status,
                    'content_type' => $headers['Content-Type'],
                    'body' => logBody($downstreamBody, $logBodyEnabled, $logMaxBytes),
                ]);
                return;
            }

            if (!$finished['headers_committed']) {
                if ($status >= 300) {
                    $downstreamBody = forwardUpstreamResponse($finished['connection'], $response, $finished['route']['client_alias']);
                    $log('downstream.response', [
                        'request' => $id,
                        'status' => $status,
                        'content_type' => upstreamResponseHeaders($response)['Content-Type'],
                        'body' => logBody($downstreamBody, $logBodyEnabled, $logMaxBytes),
                    ]);
                    return;
                }
                $finished['connection']->send(errorResponse($status >= 400 ? $status : 502, 'B.AI returned an unusable stream response.'));
                $log('downstream.response', [
                    'request' => $id,
                    'status' => $status >= 400 ? $status : 502,
                    'body' => logBody('B.AI returned an unusable stream response.', $logBodyEnabled, $logMaxBytes),
                ]);
                return;
            }
            if ($finished['stream_bytes'] === 0 && $finished['pending'] === '') {
                $finished['connection']->send(new Chunk(streamError('B.AI returned an empty stream.')));
                $finished['connection']->send(new Chunk(''));
                $log('downstream.stream_complete', ['request' => $id, 'result' => 'empty_stream']);
                return;
            }
            $tail = rewriteSse($finished['pending'], "\n\n", $finished['sse_state'], $finished['route']['client_alias']);
            if ($tail !== '') {
                $finished['connection']->send(new Chunk($tail));
            }
            $repair = finishSse($finished['sse_state'], (bool) ($finished['model']['repair_sse'] ?? false));
            if ($repair !== '') {
                $finished['connection']->send(new Chunk($repair));
            }
            $finished['connection']->send(new Chunk(''));
            $log('downstream.stream_complete', ['request' => $id, 'result' => 'ok']);
        },
        'error' => static function ($error) use (&$scheduler, &$complete, $id, $attempt, $log, $logBodyEnabled, $logMaxBytes): void {
            $finished = $complete($id, $attempt);
            if ($finished === null || connectionClosed($finished['connection'])) {
                return;
            }
            $log('upstream.error', [
                'request' => $id,
                'message' => $error instanceof Throwable ? $error->getMessage() : 'B.AI connection failed.',
            ]);
            if ($finished['stream'] && $finished['headers_committed']) {
                $finished['connection']->send(new Chunk(streamError('B.AI connection failed.')));
                $finished['connection']->send(new Chunk(''));
                $log('downstream.stream_complete', ['request' => $id, 'result' => 'upstream_error']);
                return;
            }
            $finished['connection']->send(errorResponse(502, 'B.AI connection failed.'));
            $log('downstream.response', [
                'request' => $id,
                'status' => 502,
                'body' => logBody('B.AI connection failed.', $logBodyEnabled, $logMaxBytes),
            ]);
        },
    ];

    if ($job['stream']) {
        $options['response'] = static function ($response) use (&$scheduler, $id, $attempt, $log): void {
            if (!isset($scheduler['jobs'][$id]) || $scheduler['jobs'][$id]['active_attempt'] !== $attempt) {
                return;
            }
            $job = &$scheduler['jobs'][$id];
            $log('upstream.stream_headers', [
                'request' => $id,
                'status' => $response->getStatusCode(),
                'content_type' => $response->getHeaderLine('Content-Type'),
                'retry_after' => $response->getHeaderLine('Retry-After'),
            ]);
            if ($response->getStatusCode() >= 300 || connectionClosed($job['connection'])) {
                return;
            }
            $job['headers_committed'] = true;
            $job['connection']->send(new Response(200, [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'Transfer-Encoding' => 'chunked',
                'X-Accel-Buffering' => 'no',
            ], ''));
            $log('downstream.stream_headers', ['request' => $id, 'status' => 200]);
        };
        $options['progress'] = static function (string $chunk) use (
            &$scheduler,
            $id,
            $attempt,
            $log,
            $logBodyEnabled,
            $logMaxBytes,
        ): void {
            if (!isset($scheduler['jobs'][$id]) || $scheduler['jobs'][$id]['active_attempt'] !== $attempt) {
                return;
            }
            $job = &$scheduler['jobs'][$id];
            if (!$job['headers_committed'] || connectionClosed($job['connection'])) {
                return;
            }
            $log('upstream.stream_chunk', [
                'request' => $id,
                'body' => logBody($chunk, $logBodyEnabled, $logMaxBytes),
            ]);
            $job['stream_bytes'] += strlen($chunk);
            $rewritten = rewriteSse($job['pending'], $chunk, $job['sse_state'], $job['route']['client_alias']);
            if ($rewritten !== '') {
                $job['connection']->send(new Chunk($rewritten));
                $log('downstream.stream_chunk', [
                    'request' => $id,
                    'body' => logBody($rewritten, $logBodyEnabled, $logMaxBytes),
                ]);
            }
        };
    }

    try {
        $log('upstream.request', [
            'request' => $id,
            'endpoint' => 'https://api.b.ai/v1/messages',
            'model' => $job['model']['upstream'],
            'stream' => $job['stream'],
            'body' => logBody($job['body'], $logBodyEnabled, $logMaxBytes),
        ]);
        $client->request('https://api.b.ai/v1/messages', $options);
    } catch (Throwable) {
        $finished = $complete($id, $attempt);
        if ($finished !== null && !connectionClosed($finished['connection'])) {
            $finished['connection']->send(errorResponse(502, 'B.AI connection failed.'));
            $log('upstream.error', ['request' => $id, 'message' => 'B.AI client could not start the request.']);
            $log('downstream.response', [
                'request' => $id,
                'status' => 502,
                'body' => logBody('B.AI connection failed.', $logBodyEnabled, $logMaxBytes),
            ]);
        }
    }
};

$pump = static function () use (&$scheduler, &$dispatch, &$discard, &$schedulePump): void {
    $now = microtime(true);
    if ($scheduler['cooldown_until'] > $now) {
        $schedulePump($scheduler['cooldown_until']);
        return;
    }
    while ($scheduler['active'] < $scheduler['window'] && $scheduler['ready'] !== []) {
        $id = array_shift($scheduler['ready']);
        if (!isset($scheduler['jobs'][$id]) || $scheduler['jobs'][$id]['state'] !== 'queued') {
            continue;
        }
        $job = $scheduler['jobs'][$id];
        if (connectionClosed($job['connection'])) {
            $discard($id);
            $scheduler['counters']['cancelled']++;
            continue;
        }
        if ($now > $job['deadline']) {
            $discard($id);
            $scheduler['counters']['expired']++;
            $job['connection']->send(errorResponse(503, 'Request expired in the B.AI queue.'));
            continue;
        }
        $scheduler['pending']--;
        $scheduler['queue_bytes'] -= strlen($job['body']);
        $dispatch($id);
    }
};

$enqueue = static function ($connection, array $job) use (
    &$scheduler,
    &$pump,
    $queueCapacity,
    $queueMaxBytes,
    $queueMaxWait,
    $log,
    $logBodyEnabled,
    $logMaxBytes,
): void {
    $bytes = strlen($job['body']);
    $id = ++$scheduler['next_id'];
    $log('downstream.request', [
        'request' => $id,
        'method' => 'POST',
        'path' => '/v1/messages',
        'model' => $job['route']['client_alias'],
        'stream' => $job['stream'],
        'body' => logBody($job['downstream_body'], $logBodyEnabled, $logMaxBytes),
    ]);
    if ($scheduler['pending'] >= $queueCapacity || $scheduler['queue_bytes'] + $bytes > $queueMaxBytes) {
        $scheduler['counters']['rejected']++;
        $connection->send(errorResponse(503, 'B.AI proxy queue is full.'));
        $log('downstream.response', [
            'request' => $id,
            'status' => 503,
            'body' => logBody('B.AI proxy queue is full.', $logBodyEnabled, $logMaxBytes),
        ]);
        return;
    }
    $job += [
        'connection' => $connection,
        'created_at' => microtime(true),
        'deadline' => microtime(true) + $queueMaxWait,
        'retries' => 0,
        'state' => 'queued',
        'active_attempt' => 0,
        'headers_committed' => false,
        'stream_bytes' => 0,
        'pending' => '',
        'sse_state' => ['started' => false, 'stopped' => false, 'blocks' => []],
    ];
    $scheduler['jobs'][$id] = $job;
    $scheduler['ready'][] = $id;
    $scheduler['pending']++;
    $scheduler['queue_bytes'] += $bytes;
    $pump();
};

$worker->onWorkerStart = static function () use (
    &$client,
    &$scheduler,
    &$discard,
    &$pump,
    $upstreamTimeout,
    $maxConcurrency,
): void {
    $client = new Client([
        'connect_timeout' => 30,
        'timeout' => $upstreamTimeout,
        'keepalive_timeout' => 30,
        'max_conn_per_addr' => $maxConcurrency,
    ]);
    Timer::add(1, static function () use (&$scheduler, &$discard, &$pump): void {
        foreach ($scheduler['jobs'] as $id => $job) {
            if (!connectionClosed($job['connection']) || $job['state'] === 'in_flight') {
                continue;
            }
            $discard($id);
            $scheduler['counters']['cancelled']++;
        }
        $pump();
    });
};

$worker->onMessage = function ($connection, Request $request) use (
    &$client,
    &$scheduler,
    &$enqueue,
    $models,
    $apiKey,
    $queueCapacity,
    $maxConcurrency,
): void {
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
        $connection->send(jsonResponse(200, [
            'status' => $scheduler['cooldown_until'] > microtime(true) || $scheduler['pending'] >= $queueCapacity ? 'degraded' : 'ok',
            'upstream' => 'B.AI',
            'model_count' => count($models),
            'active' => $scheduler['active'],
            'queued' => $scheduler['pending'],
            'queue_bytes' => $scheduler['queue_bytes'],
            'effective_concurrency' => $scheduler['window'],
            'max_concurrency' => $maxConcurrency,
            'cooldown_ms' => max(0, (int) round(($scheduler['cooldown_until'] - microtime(true)) * 1000)),
            'counters' => $scheduler['counters'],
        ]));
        return;
    }

    if (in_array($method, ['GET', 'POST', 'HEAD'], true) && $path === '/api/hello') {
        $connection->send($method === 'HEAD'
            ? new Response(200, ['Content-Type' => 'application/json; charset=utf-8'])
            : jsonResponse(200, ['status' => 'ok', 'upstream' => 'B.AI', 'model_count' => count($models)]));
        return;
    }

    if ($method === 'GET' && $path === '/v1/models') {
        $data = array_map(static fn (array $model): array => [
            'id' => $model['alias'],
            'type' => 'model',
            'created_at' => '1970-01-01T00:00:00Z',
            'display_name' => $model['name'],
            'max_input_tokens' => null,
            'max_tokens' => $model['max_tokens'],
            'capabilities' => null,
        ], $models);
        $connection->send(jsonResponse(200, [
            'data' => $data,
            'first_id' => $data[0]['id'] ?? null,
            'has_more' => false,
            'last_id' => $data === [] ? null : $data[array_key_last($data)]['id'],
        ]));
        return;
    }

    if ($method !== 'POST' || !in_array($path, ['/v1/messages', '/v1/messages/count_tokens'], true)) {
        $connection->send(errorResponse(404, 'Only B.AI message and discovery endpoints are available.'));
        return;
    }

    // Keep JSON objects as stdClass so empty Schema objects ({}) do not turn
    // into arrays ([]) when the request is encoded again for B.AI.
    $payload = json_decode($request->rawBody());
    if (!$payload instanceof stdClass) {
        $connection->send(errorResponse(400, 'The request body must be a valid JSON object.'));
        return;
    }

    if ($path === '/v1/messages/count_tokens') {
        $connection->send(jsonResponse(200, ['input_tokens' => tokenCount($payload)]));
        return;
    }

    $route = findModel($models, (string) ($payload->model ?? ''));
    if ($route === null) {
        $connection->send(errorResponse(400, 'This model is not offered by this B.AI proxy.'));
        return;
    }
    if (!$client instanceof Client) {
        $connection->send(errorResponse(500, 'B.AI client is unavailable.'));
        return;
    }

    $model = $route['model'];
    $requestedTokens = (int) ($payload->max_tokens ?? 0);
    if ($requestedTokens <= 0) {
        $connection->send(errorResponse(400, 'max_tokens must be a positive integer.'));
        return;
    }
    $payload->model = $model['upstream'];
    $payload->max_tokens = $requestedTokens <= 2 ? 16 : min($requestedTokens, $model['max_tokens']);
    $stream = (bool) ($payload->stream ?? false);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        $connection->send(errorResponse(400, 'Unable to encode the request body.'));
        return;
    }

    $headers = [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type' => 'application/json',
        'Accept' => $stream ? 'text/event-stream' : 'application/json',
        'anthropic-version' => $request->header('anthropic-version', '2023-06-01'),
    ];
    if (($beta = $request->header('anthropic-beta', '')) !== '') {
        $headers['anthropic-beta'] = $beta;
    }

    $enqueue($connection, [
        'downstream_body' => $request->rawBody(),
        'body' => $body,
        'headers' => $headers,
        'stream' => $stream,
        'route' => $route,
        'model' => $model,
    ]);
};

fwrite(STDOUT, sprintf("B.AI proxy listening on http://%s:%d\n", HOST, PORT));
Worker::runAll();
