<?php

declare(strict_types=1);

use FreeGateway\Provider\ProviderAdapter;
use FreeGateway\ProviderConfig;
use FreeGateway\Providers\Bai\Provider;
use FreeGateway\ModelAliasRegistry;
use FreeGateway\SecretGuard;

require_once __DIR__ . '/Provider.php';

return static fn (ProviderConfig $config, SecretGuard $guard, ModelAliasRegistry $aliases): ProviderAdapter => new Provider(
    $config->get('API_KEY'),
    $config->get('BASE_URL', 'https://api.b.ai/v1'),
    $guard,
    $aliases,
    $config->int('CONCURRENCY', 2, 1, 64),
);
