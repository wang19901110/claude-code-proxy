<?php

declare(strict_types=1);

namespace FreeGateway;

use FreeGateway\Provider\ProviderAdapter;
use RuntimeException;

final class ProviderRegistry
{
    private ?SecretGuard $guard = null;

    public function __construct(private readonly string $directory)
    {
    }

    /** @return array<string, ProviderAdapter> */
    public function load(): array
    {
        $packages = [];
        $platformIndexes = [];
        $directories = glob(rtrim($this->directory, '\\/') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        sort($directories, SORT_STRING);

        foreach ($directories as $directory) {
            $id = basename($directory);
            if (str_starts_with($id, '_')) {
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9-]*$/D', $id)) {
                throw new RuntimeException("Invalid provider directory name: {$id}");
            }
            $entry = $directory . DIRECTORY_SEPARATOR . 'bootstrap.php';
            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'provider.json';
            if (!is_file($entry) && !is_file($manifestPath)) {
                continue;
            }
            if (!is_file($entry) || !is_file($manifestPath)) {
                throw new RuntimeException("Provider {$id} requires both bootstrap.php and provider.json.");
            }
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $platformIndex = is_array($manifest) ? filter_var($manifest['platform_index'] ?? null, FILTER_VALIDATE_INT) : false;
            if ($platformIndex === false || $platformIndex < 1) {
                throw new RuntimeException("Provider {$id} has an invalid platform_index.");
            }
            if (isset($platformIndexes[$platformIndex])) {
                throw new RuntimeException("Providers {$platformIndexes[$platformIndex]} and {$id} use the same platform_index.");
            }
            $platformIndexes[$platformIndex] = $id;
            $packages[$id] = [
                'entry' => $entry,
                'config' => ProviderConfig::fromDirectory($id, $directory),
                'aliases' => new ModelAliasRegistry(
                    $id,
                    $platformIndex,
                    dirname($this->directory) . '/runtime/provider-aliases/' . $id . '.json',
                ),
            ];
        }

        $secretValues = [];
        foreach ($packages as $package) {
            array_push($secretValues, ...$package['config']->secrets());
        }
        $guard = $this->guard = new SecretGuard(array_values(array_unique($secretValues)));

        $providers = [];
        foreach ($packages as $id => $package) {
            $factory = require $package['entry'];
            if (!is_callable($factory)) {
                throw new RuntimeException("Provider {$id} bootstrap must return a callable factory.");
            }
            $provider = $factory($package['config'], $guard, $package['aliases']);
            if (!$provider instanceof ProviderAdapter) {
                throw new RuntimeException("Provider {$id} factory must return ProviderAdapter.");
            }
            if ($provider->name() !== $id) {
                throw new RuntimeException("Provider {$id} returned mismatched name {$provider->name()}.");
            }
            $providers[$id] = $provider;
        }

        if ($providers === []) {
            throw new RuntimeException("No provider packages found in {$this->directory}.");
        }

        return $providers;
    }

    public function guard(): SecretGuard
    {
        if ($this->guard === null) {
            throw new RuntimeException('Provider registry must be loaded before requesting its secret guard.');
        }
        return $this->guard;
    }
}
