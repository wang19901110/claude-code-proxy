<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

use FreeGateway\AnthropicProtocol;
use PHPUnit\Framework\TestCase;

final class AnthropicProtocolTest extends TestCase
{
    public function testRewritesOnlyTopLevelResponseModel(): void
    {
        $body = json_encode([
            'model' => 'upstream',
            'content' => [['type' => 'tool_use', 'input' => ['model' => 'do-not-touch']]],
        ]);
        $result = json_decode(AnthropicProtocol::rewriteJson((string) $body, 'claude-free-auto'), true);
        self::assertSame('claude-free-auto', $result['model']);
        self::assertSame('do-not-touch', $result['content'][0]['input']['model']);
    }

    public function testExtractsCapabilitiesWithoutRejectingUnknownFields(): void
    {
        $payload = json_decode(json_encode([
            'tools' => [['name' => 'read']],
            'tool_choice' => ['type' => 'auto'],
            'thinking' => ['type' => 'enabled'],
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64']],
                ['type' => 'text', 'text' => 'hello', 'cache_control' => ['type' => 'ephemeral']],
            ]]],
            'future_field' => ['kept' => true],
        ]));
        self::assertEqualsCanonicalizing(
            ['tools', 'tool_choice', 'thinking', 'prompt_caching', 'vision'],
            AnthropicProtocol::requirements($payload),
        );
    }

    public function testTokenEstimateIsConservativeAndPositive(): void
    {
        $english = (object) ['messages' => [['content' => str_repeat('a', 350)]]];
        $chinese = (object) ['messages' => [['content' => str_repeat('中', 100)]]];
        self::assertGreaterThan(100, AnthropicProtocol::estimateTokens($english));
        self::assertGreaterThan(100, AnthropicProtocol::estimateTokens($chinese));
    }
}
