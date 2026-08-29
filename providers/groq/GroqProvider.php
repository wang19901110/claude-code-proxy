<?php

declare(strict_types=1);

namespace ClaudeCodeProxy\Providers\Groq;

use ClaudeCodeProxy\AnthropicToOpenAITransformer;
use ClaudeCodeProxy\OpenAIChatResponseAdapter;
use ClaudeCodeProxy\ProviderInterface;
use ClaudeCodeProxy\ResponseAdapterInterface;
use RuntimeException;
use Workerman\Protocols\Http\Request;

final class GroqProvider implements ProviderInterface
{
    /** @var array<string, string> */
    private array $env;

    public function __construct(private readonly string $directory = __DIR__)
    {
        $this->env = $this->loadEnv($this->directory . '/.env');
    }

    public function id(): string { return 'groq'; }

    public function displayName(): string { return 'Groq'; }

    public function isConfigured(): bool
    {
        return trim($this->env['GROQ_API_KEY'] ?? '') !== '';
    }

    public function configurationHint(): string
    {
        return 'Copy providers/groq/.env.example to providers/groq/.env and set GROQ_API_KEY.';
    }

    public function requestTimeout(int $default): int
    {
        return max(30, (int) ($this->env['UPSTREAM_TIMEOUT'] ?? $default));
    }

    public function models(): array
    {
        return [
            [
                'alias' => 'groq-qwen3.8-27b',
                'upstream_model' => 'qwen/qwen3.8-27b',
                'display_name' => 'Groq · Qwen3.8 27B',
                'max_tokens' => 16384,
            ],
            [
                'alias' => 'groq-gpt-oss-120b',
                'upstream_model' => 'openai/gpt-oss-120b',
                'display_name' => 'Groq · GPT-OSS 120B',
                'max_tokens' => 65536,
            ],
        ];
    }

    public function prepareRequest(array $payload, array $model, Request $request): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Groq is not configured.');
        }

        $requestedMaxTokens = isset($payload['max_tokens']) ? (int) $payload['max_tokens'] : 0;
        $maxTokens = $requestedMaxTokens <= 2 ? 16 : (int) $model['max_tokens'];
        $body = (new AnthropicToOpenAITransformer())->transform(
            $payload,
            (string) $model['upstream_model'],
            $maxTokens,
            'max_completion_tokens',
        );
        if ($body['stream']) {
            $body['stream_options'] = ['include_usage' => true];
        }
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode the Groq request body.');
        }

        return [
            'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->env['GROQ_API_KEY'],
                'Content-Type' => 'application/json',
                'Accept' => $body['stream'] ? 'text/event-stream' : 'application/json',
            ],
            'body' => $encoded,
            'stream' => (bool) $body['stream'],
            'requested_max_tokens' => $requestedMaxTokens,
            'max_tokens' => $maxTokens,
        ];
    }

    public function responseAdapter(array $model, string $clientModel): ResponseAdapterInterface
    {
        return new OpenAIChatResponseAdapter($clientModel);
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

return new GroqProvider(__DIR__);
