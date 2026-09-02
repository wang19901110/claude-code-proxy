<?php

declare(strict_types=1);

namespace FreeGateway;

final class Alias
{
    public static function for(int $platformIndex, int $modelIndex): string
    {
        if ($platformIndex < 1 || $modelIndex < 1) {
            throw new \InvalidArgumentException('Platform and model indexes must be positive integers.');
        }
        return sprintf('claude-sonnet-%d-%d', $platformIndex, $modelIndex);
    }

    public static function isSpecific(string $alias): bool
    {
        return preg_match('/^claude-sonnet-[1-9]\d*-[1-9]\d*$/D', $alias) === 1;
    }
}
