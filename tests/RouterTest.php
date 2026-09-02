<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

use FreeGateway\CatalogService;
use FreeGateway\Failure;
use FreeGateway\FailureKind;
use FreeGateway\ModelDescriptor;
use FreeGateway\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/claude-free-router-test-' . bin2hex(random_bytes(6)) . '.json';
        $models = [
            new ModelDescriptor('bai', 'fast', 'claude-sonnet-1-1', 'fast', ['tools'], ['text'], 32768, 10, 'test', time()),
            new ModelDescriptor('secondary', 'vision:free', 'claude-sonnet-2-1', 'vision', ['tools', 'vision'], ['text', 'image'], 65536, 100, 'test', time()),
        ];
        file_put_contents($this->cache, json_encode([
            'saved_at' => time(), 'providers' => [],
            'models' => array_map(static fn (ModelDescriptor $model): array => $model->toArray(), $models),
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->cache);
    }

    public function testExplicitAliasNeverReturnsAnotherModel(): void
    {
        $router = new Router(new CatalogService([], $this->cache, 86400));
        $candidates = $router->candidates('claude-sonnet-1-1', ['tools'], '', 4);
        self::assertCount(1, $candidates);
        self::assertSame('bai', $candidates[0]->provider);
    }

    public function testAutoHardFiltersCapabilitiesAndMovesOffCooldown(): void
    {
        $router = new Router(new CatalogService([], $this->cache, 86400));
        $vision = $router->candidates('claude-free-auto', ['tools', 'vision'], 'session', 4);
        self::assertCount(1, $vision);
        self::assertSame('secondary', $vision[0]->provider);

        $all = $router->candidates('claude-free-auto', ['tools'], 'session', 4);
        self::assertSame('bai', $all[0]->provider);
        $router->failure($all[0], new Failure(FailureKind::RATE_LIMIT, true, 600, 'model', 429));
        $afterFailure = $router->candidates('claude-free-auto', ['tools'], 'session', 4);
        self::assertSame('secondary', $afterFailure[0]->provider);
    }

    public function testSuccessfulSelectionBecomesSessionSticky(): void
    {
        $router = new Router(new CatalogService([], $this->cache, 86400));
        $models = $router->candidates('claude-free-auto', ['tools'], 'session', 4);
        $secondary = $models[1];
        $router->success($secondary, 'session', 0.2);
        $sticky = $router->candidates('claude-free-auto', ['tools'], 'session', 4);
        self::assertSame($secondary->alias, $sticky[0]->alias);
    }

    public function testAutoInterleavesProvidersBeforeRepeatingOne(): void
    {
        $extra = new ModelDescriptor('bai', 'second', 'claude-sonnet-1-2', 'second', ['tools'], ['text'], 32768, 11, 'test', time());
        $payload = json_decode((string) file_get_contents($this->cache), true);
        $payload['models'][] = $extra->toArray();
        file_put_contents($this->cache, json_encode($payload));

        $router = new Router(new CatalogService([], $this->cache, 86400));
        $models = $router->candidates('claude-free-auto', ['tools'], '', 3);
        self::assertSame(['bai', 'secondary', 'bai'], array_map(
            static fn (ModelDescriptor $model): string => $model->provider,
            $models,
        ));
    }
}
