<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Intelligence;

use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Renditions\Renditions;

/** Resolve through the existing variant-provider contract, not uploader-specific globals. */
final class AnalysisSource
{
    public function resolve(object $workspace, object $entry, object $version, Principal $actor): ?object
    {
        $source = config('media-studio.intelligence.input', ['type' => 'auto', 'missing' => 'temporary']);
        if (! is_array($source) || ! in_array($source['type'] ?? null, ['auto', 'original', 'rendition', 'provider'], true) || ! in_array($source['missing'] ?? null, ['temporary', 'wait', 'skip'], true)) {
            throw new \LogicException('Invalid AI input selection.');
        }
        if ($source['type'] === 'original') {
            return $version;
        }
        $preset = $source['preset'] ?? config('media-studio.intelligence.analysis_preset', 'ai-analysis-v1');
        if (! is_string($preset) || ! preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $preset)) {
            throw new \LogicException('Invalid analysis preset.');
        }
        if ($source['type'] === 'provider') {
            $provider = $source['provider'] ?? null;
            if (! is_string($provider) || $provider === '') {
                throw new \LogicException('Configure an external variant provider.');
            }
            $variant = app(Renditions::class)->external($provider, $workspace, $entry, $version, $actor)[$preset] ?? null;
            if ($variant !== null) {
                return (object) ['disk' => $variant->disk, 'object_key' => $variant->objectKey, 'mime_type' => $variant->mimeType, 'size_bytes' => $variant->sizeBytes, 'sha256' => $variant->sha256];
            }
        } else {
            $row = Renditions::published()->where('entry_id', $entry->id)->where('version_id', $version->id)->where('preset', $preset)->first();
            if ($row !== null) {
                app(Renditions::class)->assertExternal($workspace, $entry, $row, $actor);

                return $row;
            }
        }

        return $source['missing'] === 'temporary' ? $version : null;
    }
}
