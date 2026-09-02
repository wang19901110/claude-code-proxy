<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

use FreeGateway\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/claude-free-config-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testDoesNotRequireLocalApiKey(): void
    {
        file_put_contents($this->path, "GATEWAY_HOST=127.0.0.1\n");
        $config = Config::fromFile($this->path, dirname($this->path));
        self::assertSame('127.0.0.1', $config->host);
    }

    public function testRejectsNonLocalBinding(): void
    {
        file_put_contents($this->path, "GATEWAY_HOST=0.0.0.0\n");
        $this->expectException(RuntimeException::class);
        Config::fromFile($this->path, dirname($this->path));
    }
}
