<?php

declare(strict_types=1);

namespace FreeGateway\Provider;

use FreeGateway\Failure;
use FreeGateway\FailureKind;
use FreeGateway\ModelDescriptor;
use FreeGateway\RequestHeaders;
use FreeGateway\SecretGuard;
use FreeGateway\UpstreamRequest;

abstract class AbstractAnthropicProvider implements ProviderAdapter
{
    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $baseUrl,
        protected readonly SecretGuard $secrets,
    ) {
    }

    public function configured(): bool
    {
        return $this->apiKey !== '';
    }

    public function prepare(
        string $operation,
        ModelDescriptor $model,
        object $payload,
        RequestHeaders $headers,
    ): UpstreamRequest {
        $copy = json_decode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        if (!$copy instanceof \stdClass) {
            $copy = new \stdClass();
        }
        $copy->model = $model->upstreamId;
        $copy = $this->secrets->redactValue($copy);
        $encoded = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $outgoingHeaders = $headers->anthropic();
        $outgoingHeaders['Authorization'] = 'Bearer ' . $this->apiKey;
        $outgoingHeaders['Content-Type'] = 'application/json';
        $outgoingHeaders['Accept'] = !empty($copy->stream) ? 'text/event-stream' : 'application/json';
        $outgoingHeaders['Connection'] = 'close';
        if ($headers->get('anthropic-version') === '') {
            $outgoingHeaders['anthropic-version'] = '2023-06-01';
        }
        $outgoingHeaders += $this->extraHeaders();

        return new UpstreamRequest(
            rtrim($this->baseUrl, '/') . ($operation === 'count_tokens' ? '/messages/count_tokens' : '/messages'),
            $outgoingHeaders,
            $encoded === false ? '{}' : $encoded,
        );
    }

    /** @return array<string, string> */
    protected function extraHeaders(): array
    {
        return [];
    }

    public function classify(int $status, string $body = '', mixed $error = null): Failure
    {
        if ($error !== null) {
            return new Failure(FailureKind::UPSTREAM_TRANSIENT, true, 120, 'model', 502);
        }
        $lower = strtolower($body);
        if ($status === 401) {
            return new Failure(FailureKind::AUTH, true, PHP_INT_MAX, 'provider', $status);
        }
        if ($status === 403) {
            return new Failure(FailureKind::FORBIDDEN, true, 3600, 'model', $status);
        }
        if ($status === 404) {
            return new Failure(FailureKind::MODEL_UNAVAILABLE, true, 3600, 'model', $status);
        }
        if ($status === 429) {
            return new Failure(FailureKind::RATE_LIMIT, true, 600, 'model', $status);
        }
        if ($status >= 500) {
            return new Failure(FailureKind::UPSTREAM_TRANSIENT, true, 120, 'model', $status);
        }
        if (in_array($status, [400, 422], true)) {
            if (preg_match('/context|too many tokens|prompt.{0,16}(long|large)|maximum context/', $lower)) {
                return new Failure(FailureKind::CONTEXT_EXCEEDED, true, 300, 'model', $status);
            }
            if (preg_match('/unsupported|not supported|extra inputs|thinking|tool|cache_control|output_config/', $lower)) {
                return new Failure(FailureKind::UNSUPPORTED_CAPABILITY, true, 600, 'model', $status);
            }
            return new Failure(FailureKind::CLIENT_INVALID, false, 0, 'request', $status);
        }
        return new Failure(FailureKind::PROTOCOL_INVALID, false, 0, 'request', $status);
    }
}
