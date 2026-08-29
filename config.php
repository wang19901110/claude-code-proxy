<?php

declare(strict_types=1);

return [
    'host' => '127.0.0.1',
    'port' => 8787,
    'upstream_timeout' => 180,
    'connect_timeout' => 30,
    'keepalive_timeout' => 30,
    'providers_path' => __DIR__ . '/providers',
    'log_path' => __DIR__ . '/workerman.log',
];
