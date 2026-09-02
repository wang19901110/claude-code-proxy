<?php

declare(strict_types=1);

namespace FreeGateway;

final class Failure
{
    public function __construct(
        public readonly FailureKind $kind,
        public readonly bool $safeToFallback,
        public readonly int $cooldownSeconds,
        public readonly string $scope,
        public readonly int $status,
        public readonly ?float $retryAfter = null,
    ) {
    }
}
