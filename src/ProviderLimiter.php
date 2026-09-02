<?php

declare(strict_types=1);

namespace FreeGateway;

final class ProviderLimiter
{
    /** @var array<string, int> */
    private array $active = [];

    /** @var array<string, list<array{bytes:int,start:callable,reject:callable}>> */
    private array $queues = [];

    private int $queued = 0;
    private int $queuedBytes = 0;

    /** @param array<string, int> $limits */
    public function __construct(
        private readonly array $limits,
        private readonly int $maxQueued,
        private readonly int $maxQueuedBytes,
    ) {
    }

    public function run(string $provider, int $bytes, callable $start, callable $reject): void
    {
        $limit = $this->limits[$provider] ?? 1;
        if (($this->active[$provider] ?? 0) < $limit) {
            $this->start($provider, $start);
            return;
        }
        if ($this->queued >= $this->maxQueued || $this->queuedBytes + $bytes > $this->maxQueuedBytes) {
            $reject();
            return;
        }
        $this->queues[$provider][] = ['bytes' => $bytes, 'start' => $start, 'reject' => $reject];
        $this->queued++;
        $this->queuedBytes += $bytes;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return ['active' => $this->active, 'queued' => $this->queued, 'queued_bytes' => $this->queuedBytes];
    }

    private function start(string $provider, callable $start): void
    {
        $this->active[$provider] = ($this->active[$provider] ?? 0) + 1;
        $released = false;
        $start(function () use ($provider, &$released): void {
            if ($released) {
                return;
            }
            $released = true;
            $this->active[$provider] = max(0, ($this->active[$provider] ?? 1) - 1);
            $queue = $this->queues[$provider] ?? [];
            $next = array_shift($queue);
            if ($queue === []) {
                unset($this->queues[$provider]);
            } else {
                $this->queues[$provider] = $queue;
            }
            if ($next !== null) {
                $this->queued--;
                $this->queuedBytes -= $next['bytes'];
                $this->start($provider, $next['start']);
            }
        });
    }
}
