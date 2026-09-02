<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

use FreeGateway\SecretGuard;
use PHPUnit\Framework\TestCase;

final class SecretGuardTest extends TestCase
{
    public function testRedactsSecretsRecursivelyWithoutChangingShape(): void
    {
        $guard = new SecretGuard(['sk-secret']);
        $value = json_decode('{"messages":[{"content":"token=sk-secret"}],"tools":[{"input_schema":{"model":"x"}}]}');
        $redacted = $guard->redactValue($value);
        $json = json_encode($redacted);
        self::assertStringNotContainsString('sk-secret', (string) $json);
        self::assertSame('x', $redacted->tools[0]->input_schema->model);
    }
}
