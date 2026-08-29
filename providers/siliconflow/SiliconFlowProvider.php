<?php

declare(strict_types=1);

namespace ClaudeCodeProxy\Providers\SiliconFlow;

use ClaudeCodeProxy\AnthropicResponseAdapter;
use ClaudeCodeProxy\ProviderInterface;
use ClaudeCodeProxy\ResponseAdapterInterface;
use RuntimeException;
use Workerman\Protocols\Http\Request;

final class SiliconFlowProvider implements ProviderInterface
{
    /** @var array<string, string> */
    private array $env;

    public function __construct(private readonly string $directory = __DIR__)
    {
        $this->env = $this->loadEnv($this->directory . '/.env');
    }

    public function id(): string { return 'siliconflow'; }

    public function displayName(): string { return 'SiliconFlow'; }

    public function isConfigured(): bool
    {
        return trim($this->env['SILICONFLOW_API_KEY'] ?? '') !== '';
    }

    public function configurationHint(): string
    {
        return 'Copy providers/siliconflow/.env.example to providers/siliconflow/.env and set SILICONFLOW_API_KEY.';
    }

    public function requestTimeout(int $default): int
    {
        return max(30, (int) ($this->env['UPSTREAM_TIMEOUT'] ?? $default));
    }

    public function models(): array
    {
        return [
            [
                'alias' => 'siliconflow-qwen3-8b',
                'upstream_model' => 'Qwen/Qwen3-8B',
                'display_name' => 'SiliconFlow · Qwen3 8B Free',
                'max_tokens' => 16384,
            ],
            [
                'alias' => 'siliconflow-qwen2.5-7b',
                'upstream_model' => 'Qwen/Qwen2.5-7B-Instruct',
                'display_name' => 'SiliconFlow · Qwen2.5 7B Free',
                'max_tokens' => 16384,
            ],
        ];
    }

    public function prepareRequest(array $payload, array $model, Request $request): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('SiliconFlow is not configured.');
        }

        $requestedMaxTokens = isset($payload['max_tokens']) ? (int) $payload['max_tokens'] : 0;
        $payload['model'] = (string) $model['upstream_model'];
        $payload['max_tokens'] = $requestedMaxTokens <= 2 ? 16 : (int) $model['max_tokens'];
        $stream = (bool) ($payload['stream'] ?? false);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Unable to encode the SiliconFlow request body.');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->env['SILICONFLOW_API_KEY'],
            'Content-Type' => 'application/json',
            'Accept' => $stream ? 'text/event-stream' : 'application/json',
            'anthropic-version' => $request->header('anthropic-version', '2023-06-01'),
        ];
        $beta = $request->header('anthropic-beta', '');
        if ($beta !== '') {
            $headers['anthropic-beta'] = $beta;
        }

        return [
            'endpoint' => 'https://api.siliconflow.cn/v1/messages',
            'headers' => $headers,
            'body' => $body,
            'stream' => $stream,
            'requested_max_tokens' => $requestedMaxTokens,
            'max_tokens' => (int) $payload['max_tokens'],
        ];
    }

    public function responseAdapter(array $model, string $clientModel): ResponseAdapterInterface
    {
        return new AnthropicResponseAdapter($clientModel);
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

return new SiliconFlowProvider(__DIR__);
