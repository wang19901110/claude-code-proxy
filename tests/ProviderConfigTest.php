<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

use FreeGateway\ProviderConfig;
use PHPUnit\Framework\TestCase;

final class ProviderConfigTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/provider-config-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/.env');
        @rmdir($this->directory);
        putenv('SAMPLE_API_KEY');
    }

    public function testReadsOnlyProviderLocalSettingsAndSecrets(): void
    {
        file_put_contents($this->directory . '/.env', "API_KEY=local-secret\nCONCURRENCY=3\nENABLED=yes\n");
        $config = ProviderConfig::fromDirectory('sample', $this->directory);

        self::assertSame('local-secret', $config->get('API_KEY'));
        self::assertSame(3, $config->int('CONCURRENCY', 1, 1, 4));
        self::assertTrue($config->bool('ENABLED'));
        self::assertSame(['local-secret'], $config->secrets());
    }

    public function testProviderPrefixedEnvironmentVariableOverridesLocalValue(): void
    {
        file_put_contents($this->directory . '/.env', "API_KEY=local-secret\n");
        putenv('SAMPLE_API_KEY=environment-secret');
        $config = ProviderConfig::fromDirectory('sample', $this->directory);

        self::assertSame('environment-secret', $config->get('API_KEY'));
        self::assertSame(['environment-secret'], $config->secrets());
    }
}
