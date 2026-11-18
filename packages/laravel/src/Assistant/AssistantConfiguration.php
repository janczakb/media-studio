<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Assistant;

use Laravel\Ai\AiManager;
use Laravel\Ai\AnonymousAgent;

final class AssistantConfiguration
{
    public function models(): array
    {
        $models = [];
        foreach (array_slice((array) config('media-studio.assistant.models', []), 0, 32, true) as $key => $model) {
            if (! is_string($key) || ! preg_match('/\A[a-z0-9_-]{1,80}\z/', $key) || ! is_array($model)) {
                continue;
            }
            if (! is_string($model['provider'] ?? null) || ! is_string($model['model'] ?? null) || ! is_string($model['label'] ?? null)) {
                continue;
            }
            $models[$key] = $model;
        }

        return $models;
    }

    public function available(): bool
    {
        $connection = config('media-studio.assistant.connection') ?? config('queue.default');

        return config('media-studio.assistant.enabled') === true
            && class_exists(AnonymousAgent::class)
            && app()->bound(AiManager::class)
            && ! in_array(config("queue.connections.$connection.driver"), [null, 'sync', 'null'], true)
            && $this->models() !== [];
    }

    public function publicConfiguration(): array
    {
        return ['enabled' => $this->available(), 'models' => $this->available() ? array_map(
            static fn (string $key, array $m): array => ['id' => $key, 'label' => $m['label'], 'usageWeight' => max(1, min(5, (int) ($m['usage_weight'] ?? 1)))],
            array_keys($this->models()), array_values($this->models())) : []];
    }
}
