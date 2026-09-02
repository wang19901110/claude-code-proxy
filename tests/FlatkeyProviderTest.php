<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

require_once dirname(__DIR__) . '/providers/flatkey/Provider.php';

use FreeGateway\ModelAliasRegistry;
use FreeGateway\ModelDescriptor;
use FreeGateway\Providers\Flatkey\Provider;
use FreeGateway\RequestHeaders;
use FreeGateway\SecretGuard;
use PHPUnit\Framework\TestCase;

final class FlatkeyProviderTest extends TestCase
{
    private string $aliasPath;

    protected function setUp(): void
    {
        $this->aliasPath = sys_get_temp_dir() . '/flatkey-aliases-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->aliasPath);
    }

    public function testUsesAnthropicMessagesEndpointAndTheSelectedUpstreamModel(): void
    {
        $provider = new Provider(
            'test-key',
            'https://router.flatkey.ai/v1',
            new SecretGuard(['test-key']),
            new ModelAliasRegistry('flatkey', 2, $this->aliasPath),
            2,
        );
        $model = new ModelDescriptor(
            provider: 'flatkey', upstreamId: 'deepseek-v4-flash', alias: 'claude-sonnet-2-1',
            displayName: 'test', capabilities: ['tools'], inputModalities: ['text'],
            contextLength: 32000, priority: 20, freeEvidence: 'test', catalogedAt: time(),
        );

        $request = $provider->prepare('messages', $model, (object) ['messages' => []], new RequestHeaders([]));

        self::assertSame('https://router.flatkey.ai/v1/messages', $request->url);
        self::assertSame('deepseek-v4-flash', json_decode($request->body)->model);
    }

    public function testPreservesCacheControlForFlatkeyAnthropicRequests(): void
    {
        $provider = new Provider(
            'test-key',
            'https://router.flatkey.ai/v1',
            new SecretGuard(['test-key']),
            new ModelAliasRegistry('flatkey', 2, $this->aliasPath),
            2,
        );
        $model = new ModelDescriptor(
            provider: 'flatkey', upstreamId: 'deepseek-v4-flash', alias: 'claude-sonnet-2-1',
            displayName: 'test', capabilities: ['tools', 'prompt_caching'], inputModalities: ['text'],
            contextLength: 32000, priority: 20, freeEvidence: 'test', catalogedAt: time(),
        );
        $block = (object) ['type' => 'text', 'text' => 'OK', 'cache_control' => (object) ['type' => 'ephemeral']];
        $message = (object) ['role' => 'user', 'content' => [$block]];
        $payload = (object) ['messages' => [$message]];

        $request = $provider->prepare('messages', $model, $payload, new RequestHeaders([]));

        self::assertSame('ephemeral', json_decode($request->body)->messages[0]->content[0]->cache_control->type);
    }
}
