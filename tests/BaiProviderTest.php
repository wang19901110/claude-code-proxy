<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

require_once dirname(__DIR__) . '/providers/bai/Provider.php';

use FreeGateway\ModelAliasRegistry;
use FreeGateway\ModelDescriptor;
use FreeGateway\Providers\Bai\Provider;
use FreeGateway\RequestHeaders;
use FreeGateway\SecretGuard;
use PHPUnit\Framework\TestCase;

final class BaiProviderTest extends TestCase
{
    private string $aliasPath;

    protected function setUp(): void
    {
        $this->aliasPath = sys_get_temp_dir() . '/bai-aliases-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->aliasPath);
    }

    public function testClampsClaudeCodeProbeTokenLimitForBaiOnly(): void
    {
        $provider = new Provider(
            'test-key',
            'https://example.test/v1',
            new SecretGuard(['test-key']),
            new ModelAliasRegistry('bai', 1, $this->aliasPath),
            2,
        );
        $model = new ModelDescriptor(
            provider: 'bai', upstreamId: 'glm-5.3-flash', alias: 'claude-sonnet-1-1',
            displayName: 'test', capabilities: ['tools'], inputModalities: ['text'],
            contextLength: 32768, priority: 1, freeEvidence: 'test', catalogedAt: time(),
        );
        $payload = (object) ['max_tokens' => 2, 'messages' => []];

        $request = $provider->prepare('messages', $model, $payload, new RequestHeaders([]));

        self::assertSame(2, $payload->max_tokens);
        self::assertSame(3, json_decode($request->body)->max_tokens);
    }
}
