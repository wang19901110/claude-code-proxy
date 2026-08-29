<?php

declare(strict_types=1);

namespace ClaudeCodeProxy;

final class OpenAIChatResponseAdapter implements ResponseAdapterInterface
{
    public function __construct(private readonly string $clientModel)
    {
    }

    /** @return array<string, mixed> */
    public function initialSseState(): array
    {
        return [
            'message_started' => false,
            'message_stopped' => false,
            'message_id' => null,
            'text_index' => null,
            'tool_indices' => [],
            'open_blocks' => [],
            'next_index' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'finish_reason' => null,
        ];
    }

    public function rewriteJson(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }
        if (isset($decoded['error'])) {
            return $this->encode([
                'type' => 'error',
                'error' => [
                    'type' => 'api_error',
                    'message' => (string) ($decoded['error']['message'] ?? 'Upstream API error.'),
                ],
            ]);
        }

        $choice = is_array($decoded['choices'][0] ?? null) ? $decoded['choices'][0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $content = [];
        if (is_string($message['content'] ?? null) && $message['content'] !== '') {
            $content[] = ['type' => 'text', 'text' => $message['content']];
        }
        foreach (($message['tool_calls'] ?? []) as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }
            $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
            $arguments = json_decode((string) ($function['arguments'] ?? '{}'), true);
            $content[] = [
                'type' => 'tool_use',
                'id' => (string) ($toolCall['id'] ?? 'tool_' . bin2hex(random_bytes(6))),
                'name' => (string) ($function['name'] ?? 'tool'),
                'input' => is_array($arguments) ? $arguments : [],
            ];
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        return $this->encode([
            'id' => (string) ($decoded['id'] ?? 'msg_' . bin2hex(random_bytes(12))),
            'type' => 'message',
            'role' => 'assistant',
            'content' => $content,
            'model' => $this->clientModel,
            'stop_reason' => $this->stopReason($choice['finish_reason'] ?? null),
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            ],
        ]);
    }

    /** @param array<string, mixed> $state */
    public function rewriteSseBuffer(string &$pending, string $incoming, array &$state): string
    {
        $pending .= $incoming;
        $output = '';
        while (preg_match('/\r?\n\r?\n/', $pending, $match, PREG_OFFSET_CAPTURE)) {
            $separator = $match[0][0];
            $position = $match[0][1];
            $record = substr($pending, 0, $position);
            $pending = substr($pending, $position + strlen($separator));
            foreach (preg_split('/\r?\n/', $record) ?: [] as $line) {
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $payload = ltrim(substr($line, 5));
                if ($payload === '[DONE]') {
                    $output .= $this->completeStream($state);
                    continue;
                }
                $decoded = json_decode($payload, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $output .= $this->convertChunk($decoded, $state);
            }
        }
        return $output;
    }

    /** @param array<string, mixed> $state */
    public function flushPendingSse(string &$pending, array &$state): string
    {
        if ($pending === '') {
            return '';
        }
        return $this->rewriteSseBuffer($pending, "\n\n", $state);
    }

    /** @param array<string, mixed> $state */
    public function finishSse(array &$state): string
    {
        return $this->completeStream($state);
    }

    /** @param array<string, mixed> $chunk @param array<string, mixed> $state */
    private function convertChunk(array $chunk, array &$state): string
    {
        if (isset($chunk['error'])) {
            return $this->event('error', [
                'type' => 'error',
                'error' => [
                    'type' => 'api_error',
                    'message' => (string) ($chunk['error']['message'] ?? 'Upstream API error.'),
                ],
            ]);
        }

        $usage = is_array($chunk['usage'] ?? null) ? $chunk['usage'] : [];
        $state['input_tokens'] = (int) ($usage['prompt_tokens'] ?? $state['input_tokens']);
        $state['output_tokens'] = (int) ($usage['completion_tokens'] ?? $state['output_tokens']);
        $output = $this->startMessage($chunk, $state);

        $choice = is_array($chunk['choices'][0] ?? null) ? $chunk['choices'][0] : [];
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
        if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
            if ($state['text_index'] === null) {
                $state['text_index'] = $state['next_index']++;
                $state['open_blocks'][(string) $state['text_index']] = $state['text_index'];
                $output .= $this->event('content_block_start', [
                    'type' => 'content_block_start',
                    'index' => $state['text_index'],
                    'content_block' => ['type' => 'text', 'text' => ''],
                ]);
            }
            $output .= $this->event('content_block_delta', [
                'type' => 'content_block_delta',
                'index' => $state['text_index'],
                'delta' => ['type' => 'text_delta', 'text' => $delta['content']],
            ]);
        }

        foreach (($delta['tool_calls'] ?? []) as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }
            $sourceIndex = (string) ($toolCall['index'] ?? 0);
            $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
            if (!isset($state['tool_indices'][$sourceIndex])) {
                if ($state['text_index'] !== null
                    && isset($state['open_blocks'][(string) $state['text_index']])) {
                    $output .= $this->event('content_block_stop', [
                        'type' => 'content_block_stop',
                        'index' => $state['text_index'],
                    ]);
                    unset($state['open_blocks'][(string) $state['text_index']]);
                }
                $targetIndex = $state['next_index']++;
                $state['tool_indices'][$sourceIndex] = $targetIndex;
                $state['open_blocks'][(string) $targetIndex] = $targetIndex;
                $output .= $this->event('content_block_start', [
                    'type' => 'content_block_start',
                    'index' => $targetIndex,
                    'content_block' => [
                        'type' => 'tool_use',
                        'id' => (string) ($toolCall['id'] ?? 'tool_' . bin2hex(random_bytes(6))),
                        'name' => (string) ($function['name'] ?? 'tool'),
                        'input' => new \stdClass(),
                    ],
                ]);
            }
            $arguments = (string) ($function['arguments'] ?? '');
            if ($arguments !== '') {
                $output .= $this->event('content_block_delta', [
                    'type' => 'content_block_delta',
                    'index' => $state['tool_indices'][$sourceIndex],
                    'delta' => ['type' => 'input_json_delta', 'partial_json' => $arguments],
                ]);
            }
        }

        if (($choice['finish_reason'] ?? null) !== null) {
            $state['finish_reason'] = $choice['finish_reason'];
            $output .= $this->completeStream($state);
        }
        return $output;
    }

    /** @param array<string, mixed> $chunk @param array<string, mixed> $state */
    private function startMessage(array $chunk, array &$state): string
    {
        if ($state['message_started']) {
            return '';
        }
        $state['message_started'] = true;
        $state['message_id'] = (string) ($chunk['id'] ?? 'msg_' . bin2hex(random_bytes(12)));
        return $this->event('message_start', [
            'type' => 'message_start',
            'message' => [
                'id' => $state['message_id'],
                'type' => 'message',
                'role' => 'assistant',
                'content' => [],
                'model' => $this->clientModel,
                'stop_reason' => null,
                'stop_sequence' => null,
                'usage' => ['input_tokens' => $state['input_tokens'], 'output_tokens' => 0],
            ],
        ]);
    }

    /** @param array<string, mixed> $state */
    private function completeStream(array &$state): string
    {
        if (!$state['message_started'] || $state['message_stopped']) {
            return '';
        }
        $output = '';
        foreach (array_values($state['open_blocks']) as $index) {
            $output .= $this->event('content_block_stop', [
                'type' => 'content_block_stop',
                'index' => $index,
            ]);
        }
        $output .= $this->event('message_delta', [
            'type' => 'message_delta',
            'delta' => [
                'stop_reason' => $this->stopReason($state['finish_reason']),
                'stop_sequence' => null,
            ],
            'usage' => ['output_tokens' => (int) $state['output_tokens']],
        ]);
        $output .= $this->event('message_stop', ['type' => 'message_stop']);
        $state['open_blocks'] = [];
        $state['message_stopped'] = true;
        return $output;
    }

    private function stopReason(mixed $finishReason): string
    {
        return match ((string) $finishReason) {
            'length' => 'max_tokens',
            'tool_calls', 'function_call' => 'tool_use',
            default => 'end_turn',
        };
    }

    /** @param array<string, mixed> $data */
    private function event(string $name, array $data): string
    {
        return 'event: ' . $name . "\n" . 'data: ' . $this->encode($data) . "\n\n";
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
