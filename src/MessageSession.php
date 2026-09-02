<?php

declare(strict_types=1);

namespace FreeGateway;

final class MessageSession
{
    public int $candidateIndex = 0;
    public int $attempts = 0;
    public bool $headersSent = false;
    public bool $modelCommitted = false;
    public bool $successRecorded = false;
    public bool $finished = false;
    public int $rateLimited = 0;
    public mixed $timer = null;
    public ?StreamGate $gate = null;
    public ?object $upstreamResponse = null;
    public float $attemptStartedAt = 0.0;
    public float $lastPingAt = 0.0;

    /** @var array<int, mixed> */
    public array $attemptTimers = [];

    /**
     * @param list<ModelDescriptor> $candidates
     */
    public function __construct(
        public readonly int $id,
        public readonly mixed $connection,
        public readonly object $payload,
        public readonly RequestHeaders $headers,
        public readonly string $requestedModel,
        public readonly string $sessionId,
        public readonly bool $automatic,
        public readonly bool $stream,
        public readonly array $candidates,
        public readonly float $startedAt,
    ) {
    }
}
