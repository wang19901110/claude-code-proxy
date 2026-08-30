<?php

declare(strict_types=1);

namespace ClaudeCodeProxy\Providers\Bai;

use ClaudeCodeProxy\AnthropicResponseAdapter;
use ClaudeCodeProxy\ProviderInterface;
use RuntimeException;
use Workerman\Protocols\Http\Request;

final class BaiProvider implements ProviderInterface
{
    /** @var array<string, string> */
    private array $env;

    public function __construct(private readonly string $directory = __DIR__)
    {
        $this->env = $this->loadEnv($this->directory . '/.env');
    }

    public function id(): string
    {
        return 'b_ai';
    }

    public function displayName(): string
    {
        return 'B.AI';
    }

    public function isConfigured(): bool
    {
        return trim($this->env['BAI_API_KEY'] ?? '') !== '';
    }

    public function configurationHint(): string
    {
        return 'Copy providers/b_ai/.env.example to providers/b_ai/.env and set BAI_API_KEY.';
    }

    public function requestTimeout(int $default): int
    {
        $timeout = (int) ($this->env['UPSTREAM_TIMEOUT'] ?? $default);
        return max(30, $timeout);
    }

    public function models(): array
    {
        return [
            [
                'alias' => 'claude-sonnet-1-1',
                'upstream_model' => 'deepseek-v4-flash',
                'display_name' => 'B.AI · deepseek-v4-flash',
                'max_tokens' => 32000,
                'legacy_aliases' => ['claude-sonnet-4-6', 'claude-opus_deepseek-v4-flash'],
                'repair_incomplete_sse' => false,
            ],
            [
                'alias' => 'claude-sonnet-1-2',
                'upstream_model' => 'qwen3.8-flash',
                'display_name' => 'B.AI · qwen3.8-flash',
                'max_tokens' => 64000,
                'legacy_aliases' => ['claude-opus-4-6', 'claude-opus_qwen3.8-flash'],
                'repair_incomplete_sse' => false,
            ],
            [
                'alias' => 'claude-sonnet-1-3',
                'upstream_model' => 'hy3',
                'display_name' => 'B.AI · hy3',
                'max_tokens' => 32000,
                'legacy_aliases' => ['claude-opus-4-5', 'claude-opus_hy3'],
                'repair_incomplete_sse' => true,
            ],
        ];
    }

    public function prepareRequest(array $payload, array $model, Request $request): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('B.AI is not configured.');
        }

        $requestedMaxTokens = isset($payload['max_tokens']) ? (int) $payload['max_tokens'] : 0;
        $payload['model'] = (string) $model['upstream_model'];
        $payload['max_tokens'] = $requestedMaxTokens <= 2 ? 16 : (int) $model['max_tokens'];
        $stream = (bool) ($payload['stream'] ?? false);

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Unable to encode the request body.');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->env['BAI_API_KEY'],
            'Content-Type' => 'application/json',
            'Accept' => $stream ? 'text/event-stream' : 'application/json',
            'anthropic-version' => $request->header('anthropic-version', '2023-06-01'),
        ];
        $beta = $request->header('anthropic-beta', '');
        if ($beta !== '') {
            $headers['anthropic-beta'] = $beta;
        }

        return [
            'endpoint' => 'https://api.b.ai/v1/messages',
            'headers' => $headers,
            'body' => $body,
            'stream' => $stream,
            'requested_max_tokens' => $requestedMaxTokens,
            'max_tokens' => (int) $payload['max_tokens'],
        ];
    }

    public function responseAdapter(array $model, string $clientModel): AnthropicResponseAdapter
    {
        return new AnthropicResponseAdapter(
            $clientModel,
            (bool) ($model['repair_incomplete_sse'] ?? false),
        );
    }

    /** @return array<string, string> */
    private function loadEnv(string $path): array
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
}

return new BaiProvider(__DIR__);
