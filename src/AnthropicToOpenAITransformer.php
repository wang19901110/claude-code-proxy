<?php

declare(strict_types=1);

namespace ClaudeCodeProxy;

final class AnthropicToOpenAITransformer
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function transform(
        array $payload,
        string $upstreamModel,
        int $maxTokens,
        string $maxTokensField = 'max_tokens',
    ): array {
        $body = [
            'model' => $upstreamModel,
            'messages' => [],
            $maxTokensField => $maxTokens,
            'stream' => (bool) ($payload['stream'] ?? false),
        ];

        $system = $this->textFromContent($payload['system'] ?? '');
        if ($system !== '') {
            $body['messages'][] = ['role' => 'system', 'content' => $system];
        }

        foreach (($payload['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? 'user');
            if ($role === 'assistant') {
                $body['messages'][] = $this->assistantMessage($message['content'] ?? '');
                continue;
            }
            foreach ($this->userMessages($message['content'] ?? '') as $converted) {
                $body['messages'][] = $converted;
            }
        }

        if (isset($payload['tools']) && is_array($payload['tools'])) {
            $body['tools'] = array_values(array_filter(array_map(
                fn (mixed $tool): ?array => $this->toolDefinition($tool),
                $payload['tools'],
            )));
        }
        if (isset($payload['tool_choice']) && is_array($payload['tool_choice'])) {
            $body['tool_choice'] = $this->toolChoice($payload['tool_choice']);
        }
        if (isset($payload['temperature'])) {
            $body['temperature'] = (float) $payload['temperature'];
        }
        if (isset($payload['top_p'])) {
            $body['top_p'] = (float) $payload['top_p'];
        }
        if (isset($payload['stop_sequences']) && is_array($payload['stop_sequences'])) {
            $body['stop'] = array_values($payload['stop_sequences']);
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function assistantMessage(mixed $content): array
    {
        if (is_string($content)) {
            return ['role' => 'assistant', 'content' => $content];
        }

        $text = [];
        $toolCalls = [];
        foreach (is_array($content) ? $content : [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text') {
                $text[] = (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $arguments = json_encode(
                    is_array($block['input'] ?? null) ? $block['input'] : new \stdClass(),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? 'tool_' . bin2hex(random_bytes(6))),
                    'type' => 'function',
                    'function' => [
                        'name' => (string) ($block['name'] ?? 'tool'),
                        'arguments' => $arguments === false ? '{}' : $arguments,
                    ],
                ];
            }
        }

        $message = ['role' => 'assistant', 'content' => implode('', $text)];
        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
        }
        return $message;
    }

    /** @return list<array<string, mixed>> */
    private function userMessages(mixed $content): array
    {
        if (is_string($content)) {
            return [['role' => 'user', 'content' => $content]];
        }

        $messages = [];
        $parts = [];
        $flushParts = static function () use (&$messages, &$parts): void {
            if ($parts !== []) {
                $messages[] = ['role' => 'user', 'content' => $parts];
                $parts = [];
            }
        };

        foreach (is_array($content) ? $content : [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if ($type === 'tool_result') {
                $flushParts();
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($block['tool_use_id'] ?? ''),
                    'content' => $this->textFromContent($block['content'] ?? ''),
                ];
            } elseif ($type === 'text') {
                $parts[] = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
            } elseif ($type === 'image' && is_array($block['source'] ?? null)) {
                $source = $block['source'];
                $url = ($source['type'] ?? '') === 'base64'
                    ? 'data:' . ($source['media_type'] ?? 'application/octet-stream') . ';base64,' . ($source['data'] ?? '')
                    : (string) ($source['url'] ?? '');
                if ($url !== '') {
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
                }
            }
        }
        $flushParts();
        return $messages === [] ? [['role' => 'user', 'content' => '']] : $messages;
    }

    private function textFromContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        $text = [];
        foreach (is_array($content) ? $content : [] as $block) {
            if (is_string($block)) {
                $text[] = $block;
            } elseif (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text[] = (string) ($block['text'] ?? '');
            }
        }
        return implode("\n", $text);
    }

    /** @return array<string, mixed>|null */
    private function toolDefinition(mixed $tool): ?array
    {
        if (!is_array($tool) || trim((string) ($tool['name'] ?? '')) === '') {
            return null;
        }
        return [
            'type' => 'function',
            'function' => [
                'name' => (string) $tool['name'],
                'description' => (string) ($tool['description'] ?? ''),
                'parameters' => is_array($tool['input_schema'] ?? null)
                    ? $tool['input_schema']
                    : ['type' => 'object', 'properties' => new \stdClass()],
            ],
        ];
    }

    private function toolChoice(array $choice): string|array
    {
        return match ((string) ($choice['type'] ?? 'auto')) {
            'any' => 'required',
            'none' => 'none',
            'tool' => [
                'type' => 'function',
                'function' => ['name' => (string) ($choice['name'] ?? '')],
            ],
            default => 'auto',
        };
    }
}
