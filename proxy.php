<?php

declare(strict_types=1);

use ClaudeCodeProxy\ProviderRegistry;
use ClaudeCodeProxy\ProxyLogger;
use ClaudeCodeProxy\ProxyServer;

require_once __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';
$logger = new ProxyLogger((string) $config['log_path']);

try {
    $registry = ProviderRegistry::discover((string) $config['providers_path']);
    $server = new ProxyServer($config, $registry, $logger);
    $server->run();
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
