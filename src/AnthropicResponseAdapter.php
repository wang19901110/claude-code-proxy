<?php

declare(strict_types=1);

namespace ClaudeCodeProxy;

final class AnthropicResponseAdapter
{
    public function __construct(
        private readonly string $clientModel,
        private readonly bool $repairIncompleteSse = false,
    ) {
    }

    /** @return array{message_started: bool, message_stopped: bool, open_blocks: array<string, int>} */
    public function initialSseState(): array
    {
        return [
            'message_started' => false,
            'message_stopped' => false,
            'open_blocks' => [],
        ];
    }

    public function rewriteJson(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }
        $encoded = json_encode(
            $this->replaceModelValue($decoded),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        return $encoded === false ? $json : $encoded;
    }

    /**
     * @param array{message_started: bool, message_stopped: bool, open_blocks: array<string, int>} $state
     */
    public function rewriteSseBuffer(string &$pending, string $incoming, array &$state): string
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
                $payload = ltrim(substr($line, 5));
                if ($payload === '' || $payload === '[DONE]') {
                    continue;
                }
                $this->trackSsePayload($payload, $state);
                $line = 'data: ' . $this->rewriteJson($payload);
            }
            unset($line);
            $output .= implode("\n", $lines) . "\n\n";
        }
        return $output;
    }

    /**
     * Flush an upstream record that omitted the final blank-line separator.
     *
     * @param array{message_started: bool, message_stopped: bool, open_blocks: array<string, int>} $state
     */
    public function flushPendingSse(string &$pending, array &$state): string
    {
        if ($pending === '') {
            return '';
        }
        return $this->rewriteSseBuffer($pending, "\n\n", $state);
    }

    /**
     * @param array{message_started: bool, message_stopped: bool, open_blocks: array<string, int>} $state
     */
    public function finishSse(array &$state): string
    {
        if (!$this->repairIncompleteSse || !$state['message_started'] || $state['message_stopped']) {
            return '';
        }

        $output = '';
        foreach (array_reverse(array_values($state['open_blocks'])) as $index) {
            $output .= 'event: content_block_stop' . "\n" . 'data: ' . json_encode([
                'type' => 'content_block_stop',
                'index' => $index,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        }
        $output .= 'event: message_delta' . "\n" . 'data: ' . json_encode([
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null],
            'usage' => ['output_tokens' => 0],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        $output .= 'event: message_stop' . "\n" . 'data: ' . json_encode([
            'type' => 'message_stop',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

        $state['open_blocks'] = [];
        $state['message_stopped'] = true;
        return $output;
    }

    private function replaceModelValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            if ($key === 'model' && is_string($item)) {
                $value[$key] = $this->clientModel;
                continue;
            }
            $value[$key] = $this->replaceModelValue($item);
        }
        return $value;
    }

    /**
     * @param array{message_started: bool, message_stopped: bool, open_blocks: array<string, int>} $state
     */
    private function trackSsePayload(string $payload, array &$state): void
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return;
        }

        $type = $decoded['type'] ?? '';
        if ($type === 'message_start') {
            $state['message_started'] = true;
            return;
        }
        if ($type === 'message_stop') {
            $state['message_stopped'] = true;
            return;
        }

        $index = $decoded['index'] ?? null;
        if (!is_int($index) && !is_numeric($index)) {
            return;
        }
        $key = (string) $index;
        if ($type === 'content_block_start') {
            $state['open_blocks'][$key] = (int) $index;
        } elseif ($type === 'content_block_stop') {
            unset($state['open_blocks'][$key]);
        }
    }
}
