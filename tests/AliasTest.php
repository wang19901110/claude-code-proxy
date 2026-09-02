<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

use FreeGateway\Alias;
use PHPUnit\Framework\TestCase;

final class AliasTest extends TestCase
{
    public function testAliasIsStableAndClaudeDiscoverable(): void
    {
        self::assertSame('claude-sonnet-1-1', Alias::for(1, 1));
        self::assertSame('claude-sonnet-2-9', Alias::for(2, 9));
        self::assertTrue(Alias::isSpecific('claude-sonnet-12-345'));
        self::assertFalse(Alias::isSpecific('claude-free-openrouter-model'));
    }
}
