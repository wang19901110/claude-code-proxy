<?php

declare(strict_types=1);

namespace ClaudeCodeProxy;

use RuntimeException;

final class ProviderRegistry
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    /** @var array<string, array{provider: ProviderInterface, model: array<string, mixed>, client_alias: string}> */
    private array $routes = [];

    /** @param list<ProviderInterface> $providers */
    private function __construct(array $providers)
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public static function discover(string $providersPath): self
    {
        if (!is_dir($providersPath)) {
            throw new RuntimeException('Providers directory was not found: ' . $providersPath);
        }

        $pattern = rtrim($providersPath, '/\\') . DIRECTORY_SEPARATOR . '*'
            . DIRECTORY_SEPARATOR . '*Provider.php';
        $files = glob($pattern) ?: [];
        sort($files, SORT_STRING);

        $providers = [];
        foreach ($files as $file) {
            $provider = require $file;
            if (!$provider instanceof ProviderInterface) {
                throw new RuntimeException(sprintf(
                    'Provider file must return an instance of %s: %s',
                    ProviderInterface::class,
                    $file,
                ));
            }
            $providers[] = $provider;
        }

        if ($providers === []) {
            throw new RuntimeException('No provider implementation was found under ' . $providersPath);
        }

        return new self($providers);
    }

    /** @param list<ProviderInterface> $providers */
    public static function fromProviders(array $providers): self
    {
        return new self($providers);
    }

    /** @return array<string, ProviderInterface> */
    public function providers(): array
    {
        return $this->providers;
    }

    /** @return array<string, ProviderInterface> */
    public function configuredProviders(): array
    {
        return array_filter(
            $this->providers,
            static fn (ProviderInterface $provider): bool => $provider->isConfigured(),
        );
    }

    public function hasConfiguredProviders(): bool
    {
        return $this->configuredProviders() !== [];
    }

    /** @return list<string> */
    public function configurationHints(): array
    {
        $hints = [];
        foreach ($this->providers as $provider) {
            if (!$provider->isConfigured()) {
                $hints[] = $provider->displayName() . ': ' . $provider->configurationHint();
            }
        }
        return $hints;
    }

    /** @return list<array<string, mixed>> */
    public function discoveryModels(): array
    {
        $models = [];
        foreach ($this->providers as $provider) {
            if (!$provider->isConfigured()) {
                continue;
            }
            foreach ($provider->models() as $model) {
                $models[] = $model + ['provider_id' => $provider->id()];
            }
        }
        return $models;
    }

    /** @return array{provider: ProviderInterface, model: array<string, mixed>, client_alias: string}|null */
    public function routeFor(string $requestedModel): ?array
    {
        $route = $this->routes[self::normalizeModel($requestedModel)] ?? null;
        if ($route === null || !$route['provider']->isConfigured()) {
            return null;
        }
        return $route;
    }

    /** @return array<string, array<string, mixed>> */
    public function health(): array
    {
        $health = [];
        foreach ($this->providers as $provider) {
            $configured = $provider->isConfigured();
            $health[$provider->id()] = [
                'name' => $provider->displayName(),
                'configured' => $configured,
                'model_count' => $configured ? count($provider->models()) : 0,
            ];
            if (!$configured) {
                $health[$provider->id()]['configuration_hint'] = $provider->configurationHint();
            }
        }
        return $health;
    }

    private function register(ProviderInterface $provider): void
    {
        $id = trim($provider->id());
        if ($id === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
            throw new RuntimeException('Provider ID must use lowercase snake_case: ' . $id);
        }
        if (isset($this->providers[$id])) {
            throw new RuntimeException('Duplicate provider ID: ' . $id);
        }
        $this->providers[$id] = $provider;

        foreach ($provider->models() as $model) {
            $this->validateModel($provider, $model);
            $this->registerAlias($provider, $model, (string) $model['alias']);
            foreach (($model['legacy_aliases'] ?? []) as $legacyAlias) {
                $this->registerAlias($provider, $model, (string) $legacyAlias);
            }
        }
    }

    /** @param array<string, mixed> $model */
    private function validateModel(ProviderInterface $provider, array $model): void
    {
        foreach (['alias', 'upstream_model', 'max_tokens'] as $required) {
            if (!array_key_exists($required, $model)) {
                throw new RuntimeException(sprintf(
                    'Provider %s model is missing %s.',
                    $provider->id(),
                    $required,
                ));
            }
        }
        if (!is_string($model['alias']) || trim($model['alias']) === '') {
            throw new RuntimeException('Model alias must be a non-empty string for provider ' . $provider->id());
        }
        if (!is_string($model['upstream_model']) || trim($model['upstream_model']) === '') {
            throw new RuntimeException('Upstream model must be a non-empty string for provider ' . $provider->id());
        }
        if (!is_int($model['max_tokens']) || $model['max_tokens'] < 3) {
            throw new RuntimeException('max_tokens must be an integer greater than 2 for provider ' . $provider->id());
        }
        if (isset($model['legacy_aliases']) && !is_array($model['legacy_aliases'])) {
            throw new RuntimeException('legacy_aliases must be an array for provider ' . $provider->id());
        }
    }

    /** @param array<string, mixed> $model */
    private function registerAlias(ProviderInterface $provider, array $model, string $alias): void
    {
        $normalized = self::normalizeModel($alias);
        if ($normalized === '') {
            throw new RuntimeException('Provider ' . $provider->id() . ' declared an empty model alias.');
        }
        if (isset($this->routes[$normalized])) {
            $existing = $this->routes[$normalized];
            throw new RuntimeException(sprintf(
                'Duplicate model alias %s from providers %s and %s.',
                $alias,
                $existing['provider']->id(),
                $provider->id(),
            ));
        }
        $this->routes[$normalized] = [
            'provider' => $provider,
            'model' => $model,
            'client_alias' => $alias,
        ];
    }

    public static function normalizeModel(string $model): string
    {
        return strtolower(str_replace(['.', '_'], '-', trim($model)));
    }
}
