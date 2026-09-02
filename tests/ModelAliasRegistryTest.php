<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

use FreeGateway\ModelAliasRegistry;
use PHPUnit\Framework\TestCase;

final class ModelAliasRegistryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/model-aliases-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testAssignmentsAreSequentialAndStableAcrossReloads(): void
    {
        $first = new ModelAliasRegistry('sample', 3, $this->path);
        self::assertSame('claude-sonnet-3-1', $first->for('vendor/alpha'));
        self::assertSame('claude-sonnet-3-2', $first->for('vendor/beta'));

        $reloaded = new ModelAliasRegistry('sample', 3, $this->path);
        self::assertSame('claude-sonnet-3-2', $reloaded->for('vendor/beta'));
        self::assertSame('claude-sonnet-3-1', $reloaded->for('vendor/alpha'));
        self::assertSame('claude-sonnet-3-3', $reloaded->for('vendor/gamma'));
    }
}
