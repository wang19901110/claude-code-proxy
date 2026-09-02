<?php

declare(strict_types=1);

namespace FreeGateway;

final class Router
{
    /** @var array<string, array{model:string,expires:int}> */
    private array $sessions = [];

    /** @var array<string, array{until:float,reason:string}> */
    private array $cooldowns = [];

    /** @var array<string, array{success:float,latency:float,count:int}> */
    private array $stats = [];

    /** @var array<string, true> */
    private array $disabledProviders = [];

    public function __construct(private readonly CatalogService $catalog) {}

    /**
     * @param list<string> $requirements
     * @return list<ModelDescriptor>
     */
    public function candidates(string $requested, array $requirements, string $sessionId, int $maxAttempts): array
    {
        $this->cleanup();
        if ($requested !== 'claude-free-auto') {
            $model = $this->catalog->find($requested);
            return $model !== null && $this->eligible($model, $requirements) ? [$model] : [];
        }

        $models = array_values(array_filter(
            $this->catalog->all(),
            fn (ModelDescriptor $model): bool => $this->eligible($model, $requirements),
        ));
        $sticky = $this->sessions[$sessionId]['model'] ?? null;
        usort($models, function (ModelDescriptor $a, ModelDescriptor $b) use ($sticky): int {
            $aStats = $this->stats[$a->alias] ?? ['success' => 0.5, 'latency' => 999.0, 'count' => 0];
            $bStats = $this->stats[$b->alias] ?? ['success' => 0.5, 'latency' => 999.0, 'count' => 0];
            $aKey = [$a->alias === $sticky ? 0 : 1, $a->priority, -$aStats['success'], $aStats['latency'], $a->alias];
            $bKey = [$b->alias === $sticky ? 0 : 1, $b->priority, -$bStats['success'], $bStats['latency'], $b->alias];
            return $aKey <=> $bKey;
        });
        $limit = max(1, $maxAttempts);
        $groups = [];
        $providerOrder = [];
        foreach ($models as $model) {
            if (!isset($groups[$model->provider])) {
                $groups[$model->provider] = [];
                $providerOrder[] = $model->provider;
            }
            $groups[$model->provider][] = $model;
        }
        $selected = [];
        while (count($selected) < $limit) {
            $added = false;
            foreach ($providerOrder as $provider) {
                $model = array_shift($groups[$provider]);
                if ($model instanceof ModelDescriptor) {
                    $selected[] = $model;
                    $added = true;
                    if (count($selected) >= $limit) {
                        break;
                    }
                }
            }
            if (!$added) {
                break;
            }
        }
        return $selected;
    }

    /** @param list<string> $requirements */
    private function eligible(ModelDescriptor $model, array $requirements): bool
    {
        if (isset($this->disabledProviders[$model->provider])) {
            return false;
        }
        $cooldown = $this->cooldowns[$model->alias] ?? null;
        if ($cooldown !== null && $cooldown['until'] > microtime(true)) {
            return false;
        }
        foreach ($requirements as $requirement) {
            if (!in_array($requirement, $model->capabilities, true)) {
                return false;
            }
        }
        return true;
    }

    public function success(ModelDescriptor $model, string $sessionId, float $latency): void
    {
        $current = $this->stats[$model->alias] ?? ['success' => 0.5, 'latency' => $latency, 'count' => 0];
        $alpha = 0.2;
        $this->stats[$model->alias] = [
            'success' => $current['success'] * (1 - $alpha) + $alpha,
            'latency' => $current['latency'] * (1 - $alpha) + $latency * $alpha,
            'count' => $current['count'] + 1,
        ];
        if ($sessionId !== '') {
            $this->sessions[$sessionId] = ['model' => $model->alias, 'expires' => time() + 7200];
        }
    }

    public function failure(ModelDescriptor $model, Failure $failure): void
    {
        $current = $this->stats[$model->alias] ?? ['success' => 0.5, 'latency' => 999.0, 'count' => 0];
        $this->stats[$model->alias] = [
            'success' => $current['success'] * 0.8,
            'latency' => $current['latency'],
            'count' => $current['count'] + 1,
        ];
        if ($failure->scope === 'provider') {
            $this->disabledProviders[$model->provider] = true;
        } elseif ($failure->cooldownSeconds > 0) {
            $this->cooldowns[$model->alias] = [
                'until' => microtime(true) + min($failure->cooldownSeconds, 86400),
                'reason' => $failure->kind->value,
            ];
        }
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $now = microtime(true);
        return [
            'sessions' => count($this->sessions),
            'disabled_providers' => array_keys($this->disabledProviders),
            'cooldowns' => array_map(
                static fn (array $item): array => [
                    'seconds' => max(0, (int) ceil($item['until'] - $now)),
                    'reason' => $item['reason'],
                ],
                array_filter($this->cooldowns, static fn (array $item): bool => $item['until'] > $now),
            ),
            'stats' => $this->stats,
        ];
    }

    private function cleanup(): void
    {
        $now = time();
        foreach ($this->sessions as $key => $session) {
            if ($session['expires'] <= $now) {
                unset($this->sessions[$key]);
            }
        }
        $micro = microtime(true);
        foreach ($this->cooldowns as $key => $cooldown) {
            if ($cooldown['until'] <= $micro) {
                unset($this->cooldowns[$key]);
            }
        }
    }
}
