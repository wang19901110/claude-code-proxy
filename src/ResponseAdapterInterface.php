<?php

declare(strict_types=1);

namespace ClaudeCodeProxy;

interface ResponseAdapterInterface
{
    /** @return array<string, mixed> */
    public function initialSseState(): array;

    public function rewriteJson(string $json): string;

    /** @param array<string, mixed> $state */
    public function rewriteSseBuffer(string &$pending, string $incoming, array &$state): string;

    /** @param array<string, mixed> $state */
    public function flushPendingSse(string &$pending, array &$state): string;

    /** @param array<string, mixed> $state */
    public function finishSse(array &$state): string;
}
