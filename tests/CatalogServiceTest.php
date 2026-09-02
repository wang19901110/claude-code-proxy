<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

use FreeGateway\CatalogService;
use FreeGateway\ModelDescriptor;
use PHPUnit\Framework\TestCase;

final class CatalogServiceTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/catalog-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cachePath);
    }

    public function testDiscoveryReturnsUnionOfProviderModelsPlusAuto(): void
    {
        $models = [];
        foreach (['platform-a' => 5, 'platform-b' => 5] as $provider => $count) {
            for ($index = 1; $index <= $count; $index++) {
                $models[] = (new ModelDescriptor(
                    provider: $provider,
                    upstreamId: "model-{$index}",
                    alias: 'claude-sonnet-' . ($provider === 'platform-a' ? '1' : '2') . "-{$index}",
                    displayName: "{$provider} model {$index}",
                    capabilities: ['tools'],
                    inputModalities: ['text'],
                    contextLength: 32768,
                    priority: 100,
                    freeEvidence: 'test-free-evidence',
                    catalogedAt: time(),
                ))->toArray();
            }
        }
        file_put_contents($this->cachePath, json_encode([
            'saved_at' => time(),
            'providers' => [],
            'models' => $models,
        ], JSON_THROW_ON_ERROR));

        $rows = (new CatalogService([], $this->cachePath, 86400))->discovery()['data'];

        self::assertCount(11, $rows);
        self::assertSame('claude-free-auto', $rows[0]['id']);
        self::assertCount(5, array_filter($rows, static fn (array $row): bool => str_starts_with($row['id'], 'claude-sonnet-1-')));
        self::assertCount(5, array_filter($rows, static fn (array $row): bool => str_starts_with($row['id'], 'claude-sonnet-2-')));
    }
}
