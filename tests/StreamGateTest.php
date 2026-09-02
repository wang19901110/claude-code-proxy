<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

use FreeGateway\StreamGate;
use PHPUnit\Framework\TestCase;

final class StreamGateTest extends TestCase
{
    public function testFragmentedTextStreamCommitsAndRewritesOnlyProtocolModel(): void
    {
        $stream = implode('', [
            "event: message_start\r\ndata: {\"type\":\"message_start\",\"message\":{\"model\":\"upstream\"}}\r\n\r\n",
            "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"ok\"}}\n\n",
            "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n",
        ]);
        $gate = new StreamGate('claude-free-auto');
        $output = '';
        $committed = false;
        foreach (str_split($stream) as $byte) {
            $result = $gate->feed($byte);
            $output .= $result['output'];
            $committed = $committed || $result['committed_now'];
        }
        $output .= $gate->finish()['output'];
        self::assertTrue($committed);
        self::assertStringContainsString('"model":"claude-free-auto"', $output);
        self::assertStringContainsString('"text":"ok"', $output);
        self::assertStringContainsString('message_stop', $output);
    }

    public function testToolUseCommitsAtBlockStart(): void
    {
        $gate = new StreamGate('claude-free-auto');
        $result = $gate->feed("event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"tool_use\",\"id\":\"x\",\"name\":\"read\",\"input\":{}}}\n\n");
        self::assertTrue($result['committed_now']);
        self::assertStringContainsString('tool_use', $result['output']);
    }

    public function testThinkingBufferCanBeForcedWithoutFabricatingStop(): void
    {
        $gate = new StreamGate('claude-free-auto', 262144, 0.0);
        $gate->feed("event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"thinking\",\"thinking\":\"\"}}\n\n");
        $forced = $gate->forceIfDue();
        self::assertTrue($forced['committed_now']);
        self::assertStringContainsString('thinking', $forced['output']);
        self::assertStringNotContainsString('message_stop', $forced['output']);
    }

    public function testEmptyStreamRemainsFallbackEligible(): void
    {
        $gate = new StreamGate('claude-free-auto');
        $gate->feed("event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"model\":\"x\"}}\n\nevent: message_stop\ndata: {\"type\":\"message_stop\"}\n\n");
        self::assertTrue($gate->finish()['empty']);
    }
}
