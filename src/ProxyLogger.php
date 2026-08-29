<?php

declare(strict_types=1);

namespace ClaudeCodeProxy;

final class ProxyLogger
{
    public function __construct(private readonly string $path)
    {
    }

    public function reset(): void
    {
        file_put_contents($this->path, '', LOCK_EX);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $entry = [
            'timestamp' => date(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            @file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        $this->writeConsole($level, $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function writeConsole(string $level, string $message, array $context): void
    {
        if (($context['path'] ?? '') === '/v1/messages/count_tokens'
            || ($context['endpoint'] ?? '') === 'count_tokens') {
            return;
        }

        $time = date('H:i:s');
        $requestId = $context['request_id'] ?? '-';
        $line = null;

        if ($message === 'proxy_started') {
            $line = sprintf(
                '[%s] [INFO] Proxy ready on http://%s:%d with %d providers and %d models',
                $time,
                $context['host'] ?? '127.0.0.1',
                $context['port'] ?? 0,
                $context['provider_count'] ?? 0,
                $context['model_count'] ?? 0,
            );
        } elseif ($message === 'provider_disabled') {
            $line = sprintf(
                '[%s] [WARN] Provider %s is disabled: %s',
                $time,
                $context['provider'] ?? '?',
                $context['hint'] ?? 'configuration missing',
            );
        } elseif ($message === 'request_received'
            && in_array($context['path'] ?? '', ['/v1/messages', '/v1/models'], true)) {
            $line = sprintf('[%s] [REQUEST] %s %s id=%s', $time, $context['method'] ?? '?', $context['path'], $requestId);
        } elseif ($message === 'forwarding_request') {
            $line = sprintf(
                '[%s] [FORWARD] id=%s provider=%s %s -> %s %s max_tokens=%s',
                $time,
                $requestId,
                $context['provider'] ?? '?',
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
                '[%s] [%s] id=%s provider=%s model=%s status=%s latency=%sms bytes=%s%s%s',
                $time,
                $message === 'upstream_error' || $level === 'error' ? 'ERROR' : strtoupper($level),
                $requestId,
                $context['provider'] ?? '?',
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
}
