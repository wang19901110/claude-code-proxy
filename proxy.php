<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use FreeGateway\Config;
use FreeGateway\GatewayServer;

try {
    $config = Config::fromFile(__DIR__ . '/.env', __DIR__);
    (new GatewayServer($config))->run();
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
