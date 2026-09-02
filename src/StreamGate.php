<?php

declare(strict_types=1);

namespace FreeGateway;

final class StreamGate
{
    private string $pending = '';
    private string $buffered = '';
    private bool $committed = false;
    private bool $useful = false;
    private bool $errored = false;
    private ?float $contentStartedAt = null;

    public function __construct(
        private readonly string $clientAlias,
        private readonly int $maxBufferedBytes = 262144,
        private readonly float $maxThinkingSeconds = 15.0,
    ) {
    }

    /** @return array{output:string,committed_now:bool} */
    public function feed(string $incoming): array
    {
        $this->pending .= $incoming;
        $ready = '';
        while (preg_match('/\r?\n\r?\n/', $this->pending, $match, PREG_OFFSET_CAPTURE)) {
            $offset = $match[0][1];
            $record = substr($this->pending, 0, $offset);
            $this->pending = substr($this->pending, $offset + strlen($match[0][0]));
            $rewritten = $this->inspectAndRewrite($record) . "\n\n";
            if ($this->committed) {
                $ready .= $rewritten;
            } else {
                $this->buffered .= $rewritten;
            }
        }

        $commitNow = false;
        if (!$this->committed && ($this->useful || strlen($this->buffered) >= $this->maxBufferedBytes)) {
            $this->committed = true;
            $commitNow = true;
            $ready = $this->buffered . $ready;
            $this->buffered = '';
        }
        return ['output' => $ready, 'committed_now' => $commitNow];
    }

    /** @return array{output:string,committed_now:bool} */
    public function forceIfDue(): array
    {
        if ($this->committed || $this->contentStartedAt === null
            || microtime(true) - $this->contentStartedAt < $this->maxThinkingSeconds) {
            return ['output' => '', 'committed_now' => false];
        }
        $this->committed = true;
        $output = $this->buffered;
        $this->buffered = '';
        return ['output' => $output, 'committed_now' => true];
    }

    /** @return array{output:string,empty:bool,error:bool,committed:bool} */
    public function finish(): array
    {
        if ($this->pending !== '') {
            $this->buffered .= $this->inspectAndRewrite($this->pending) . "\n\n";
            $this->pending = '';
        }
        $output = '';
        if ($this->committed) {
            $output = $this->buffered;
            $this->buffered = '';
        }
        return [
            'output' => $output,
            'empty' => !$this->useful && !$this->committed,
            'error' => $this->errored,
            'committed' => $this->committed,
        ];
    }

    public function committed(): bool
    {
        return $this->committed;
    }

    private function inspectAndRewrite(string $record): string
    {
        $lines = preg_split('/\r?\n/', $record) ?: [];
        $dataLines = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }
        if ($dataLines === []) {
            return $record;
        }
        $payload = json_decode(implode("\n", $dataLines));
        if (!$payload instanceof \stdClass) {
            return $record;
        }
        $type = (string) ($payload->type ?? '');
        if ($type === 'error') {
            $this->errored = true;
        }
        if ($type === 'content_block_start') {
            $this->contentStartedAt ??= microtime(true);
            if (($payload->content_block->type ?? '') === 'tool_use') {
                $this->useful = true;
            }
        }
        if ($type === 'content_block_delta') {
            $this->contentStartedAt ??= microtime(true);
            if (($payload->delta->type ?? '') === 'text_delta' && trim((string) ($payload->delta->text ?? '')) !== '') {
                $this->useful = true;
            }
        }
        if ($type !== 'message_start' || !(($payload->message ?? null) instanceof \stdClass)) {
            return $record;
        }
        $payload->message->model = $this->clientAlias;
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return $record;
        }
        $output = [];
        $replaced = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'data:')) {
                if (!$replaced) {
                    $output[] = 'data: ' . $encoded;
                    $replaced = true;
                }
                continue;
            }
            $output[] = $line;
        }
        return implode("\n", $output);
    }
}
