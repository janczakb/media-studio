<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Intelligence;

final class AnalysisProfile
{
    public function image(bool $video = false): array
    {
        abort_unless(config('media-studio.intelligence.enabled') === true && config('media-studio.intelligence.'.($video ? 'videos' : 'images')) === true, 404);
        $provider = config('media-studio.intelligence.provider');
        $class = match ($provider) {
            'moondream' => MoondreamAnalyzer::class,
            'rekognition' => RekognitionAnalyzer::class,
            'google_vision' => GoogleVisionAnalyzer::class,
            default => throw new \LogicException('Choose a supported image analysis provider.'),
        };
        $profile = (array) config('media-studio.intelligence.providers.'.$provider, []);
        $limit = config('media-studio.intelligence.max_labels', 24);
        if (! is_int($limit) || $limit < 1 || $limit > 64 || ($provider !== 'rekognition' && (! is_string($profile['key'] ?? null) || $profile['key'] === '')) || ($provider === 'moondream' && (! is_string($profile['model'] ?? null) || $profile['model'] === '')) || ($provider === 'rekognition' && (! is_string($profile['region'] ?? null) || $profile['region'] === ''))) {
            throw new \LogicException('Image analysis configuration is incomplete.');
        }

        return [...$profile, 'provider' => $provider, 'adapter' => $class, 'max_labels' => $limit];
    }
}
