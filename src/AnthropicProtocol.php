<?php

declare(strict_types=1);

namespace FreeGateway;

use Workerman\Protocols\Http\Response;

final class AnthropicProtocol
{
    public static function jsonResponse(int $status, array $body, array $headers = []): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );
    }

    public static function errorResponse(int $status, string $type, string $message, array $headers = []): Response
    {
        return self::jsonResponse($status, [
            'type' => 'error',
            'error' => ['type' => $type, 'message' => $message],
        ], $headers);
    }

    public static function streamError(string $type, string $message): string
    {
        return "event: error\n" . 'data: ' . (json_encode([
            'type' => 'error',
            'error' => ['type' => $type, 'message' => $message],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . "\n\n";
    }

    public static function rewriteJson(string $body, string $alias): string
    {
        $payload = json_decode($body);
        if (!$payload instanceof \stdClass) {
            return $body;
        }
        if (isset($payload->model) && is_string($payload->model)) {
            $payload->model = $alias;
        }
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $body;
    }

    /** @return list<string> */
    public static function requirements(object $payload): array
    {
        $requirements = [];
        if (is_array($payload->tools ?? null) && $payload->tools !== []) {
            $requirements[] = 'tools';
        }
        if (isset($payload->tool_choice)) {
            $requirements[] = 'tool_choice';
        }
        if (isset($payload->thinking)) {
            $requirements[] = 'thinking';
        }
        if (self::containsKey($payload, 'cache_control')) {
            $requirements[] = 'prompt_caching';
        }
        if (self::containsContentType($payload, 'image')) {
            $requirements[] = 'vision';
        }
        return array_values(array_unique($requirements));
    }

    public static function usefulJson(string $body): bool
    {
        $payload = json_decode($body);
        if (!$payload instanceof \stdClass || !is_array($payload->content ?? null)) {
            return false;
        }
        foreach ($payload->content as $block) {
            if (!$block instanceof \stdClass) {
                continue;
            }
            if (($block->type ?? '') === 'tool_use') {
                return true;
            }
            if (($block->type ?? '') === 'text' && trim((string) ($block->text ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    public static function estimateTokens(object $payload): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        preg_match_all('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $json, $cjk);
        $cjkCount = count($cjk[0]);
        $withoutCjk = preg_replace('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', '', $json) ?? $json;
        $asciiTokens = (int) ceil(strlen($withoutCjk) / 3.5);
        return max(1, (int) ceil(($cjkCount + $asciiTokens + 16) * 1.15));
    }

    /** @return array<string, string> */
    public static function upstreamHeaders(object $response): array
    {
        $headers = ['Content-Type' => $response->getHeaderLine('Content-Type') ?: 'application/json; charset=utf-8'];
        foreach (['Retry-After', 'X-Request-ID', 'Request-ID'] as $name) {
            $value = $response->getHeaderLine($name);
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    private static function containsKey(mixed $value, string $wanted): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($key === $wanted || self::containsKey($item, $wanted)) {
                    return true;
                }
            }
        } elseif ($value instanceof \stdClass) {
            foreach ($value as $key => $item) {
                if ($key === $wanted || self::containsKey($item, $wanted)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function containsContentType(mixed $value, string $wanted): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsContentType($item, $wanted)) {
                    return true;
                }
            }
        } elseif ($value instanceof \stdClass) {
            if (($value->type ?? null) === $wanted) {
                return true;
            }
            foreach ($value as $item) {
                if (self::containsContentType($item, $wanted)) {
                    return true;
                }
            }
        }
        return false;
    }
}
