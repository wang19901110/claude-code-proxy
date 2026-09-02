<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

use FreeGateway\Provider\ProviderAdapter;
use FreeGateway\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    public function testDiscoversProviderFoldersWithoutCoreRegistration(): void
    {
        $providers = (new ProviderRegistry(dirname(__DIR__) . '/providers'))->load();

        self::assertSame(['bai', 'flatkey'], array_keys($providers));
        self::assertContainsOnlyInstancesOf(ProviderAdapter::class, $providers);
    }
}
