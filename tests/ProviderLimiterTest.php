<?php

declare(strict_types=1);

namespace FreeGateway\Tests;

use FreeGateway\ProviderLimiter;
use PHPUnit\Framework\TestCase;

final class ProviderLimiterTest extends TestCase
{
    public function testReleasesImmediatelyStartedRequestWithoutQueue(): void
    {
        $limiter = new ProviderLimiter(['bai' => 1], 2, 100);
        $release = null;

        $limiter->run('bai', 1, static function (callable $callback) use (&$release): void {
            $release = $callback;
        }, static fn (): null => null);
        $release();

        self::assertSame(['active' => ['bai' => 0], 'queued' => 0, 'queued_bytes' => 0], $limiter->status());
    }

    public function testQueuesPerProviderAndReleasesInOrder(): void
    {
        $limiter = new ProviderLimiter(['bai' => 1], 2, 100);
        $events = [];
        $firstRelease = null;
        $limiter->run('bai', 10, function ($release) use (&$events, &$firstRelease): void {
            $events[] = 'first';
            $firstRelease = $release;
        }, fn () => $events[] = 'rejected');
        $limiter->run('bai', 10, function ($release) use (&$events): void {
            $events[] = 'second';
            $release();
        }, fn () => $events[] = 'rejected');
        self::assertSame(['first'], $events);
        self::assertSame(1, $limiter->status()['queued']);
        $firstRelease();
        self::assertSame(['first', 'second'], $events);
        self::assertSame(0, $limiter->status()['queued']);
    }

    public function testRejectsWhenQueueCapacityIsExceeded(): void
    {
        $limiter = new ProviderLimiter(['bai' => 1], 1, 10);
        $events = [];
        $limiter->run('bai', 1, fn ($release) => null, fn () => null);
        $limiter->run('bai', 10, fn ($release) => null, fn () => null);
        $limiter->run('bai', 1, fn ($release) => null, function () use (&$events): void { $events[] = 'rejected'; });
        self::assertSame(['rejected'], $events);
    }
}
