<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Processing;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use UseFlex\MediaCore\Images\EncodedImageVerifier;
use UseFlex\MediaCore\Images\GdImageRenderer;
use UseFlex\MediaCore\Images\ImageGeometry;
use UseFlex\MediaCore\Images\ImagePreset;
use UseFlex\MediaCore\Images\ImagickImageRenderer;
use UseFlex\MediaCore\Storage\StreamObjectStore;
use UseFlex\MediaCore\Video\VideoRenderer;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\ActivityLog;
use UseFlex\MediaStudio\Audio\AudioWaveforms;
use UseFlex\MediaStudio\DocumentThumbnails\DocumentThumbnails;
use UseFlex\MediaStudio\DocumentThumbnails\EncodedThumbnailGenerator;
use UseFlex\MediaStudio\DocumentThumbnails\ThumbnailGenerator;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Renditions\ImagePresets;
use UseFlex\MediaStudio\Renditions\Renditions;
use UseFlex\MediaStudio\Renditions\VideoRecipes;
use UseFlex\MediaStudio\Storage\LaravelObjectStore;

final class ThumbnailProcessor
{
    public function supports(object $entry): bool
    {
        return $entry->media_type === 'image' && in_array($entry->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)
            && ($entry->size_bytes ?? 0) <= config('media-studio.processing.thumbnails.max_source_bytes', 26214400);
    }

    public function schedule(object $workspace, object $entry, Principal $principal, ?Model $owner = null, ?string $replace = null, ?string $inlineImagePreset = null): int
    {
        if ($replace !== null && ! in_array($replace, ['keep', 'prune'], true)) {
            throw new \InvalidArgumentException('Invalid rendition retention policy.');
        }
        if (($entry->source_kind ?? 'managed') === 'disk') {
            app(RenditionSource::class)->authorize($workspace, $principal, $entry);
        }
        $enabled = config('media-studio.processing.native.enabled', true);
        if (is_string($enabled) && class_exists($enabled)) {
            $enabled = app($enabled);
        }
        if (is_callable($enabled)) {
            $enabled = $enabled($workspace, $entry, $principal);
        }
        if ($replace === null && $entry->media_type === 'audio') {
            app(AudioWaveforms::class)->schedule($workspace, $entry, $principal);
        }
        $recipes = [];
        $documentRecipe = app(DocumentThumbnails::class)->recipe($entry, $owner === null ? null : $owner::class);
        if ($documentRecipe !== null) {
            $recipes[config('media-studio.processing.thumbnails.preset', 'thumbnail-v1')] = $documentRecipe;
        } elseif ($entry->media_type === 'video' && VideoRenderer::supports($entry->mime_type)) {
            $recipes = VideoRecipes::recipes();
        } elseif ($enabled === true && $this->supports($entry)) {
            $model = $owner === null
                ? DB::table('fms_asset_versions')->where('id', $entry->current_version_id)->value('rendition_model')
                : $owner::class;
            foreach (ImagePresets::forModel($model) as $preset) {
                if (($preset->name === config('media-studio.processing.thumbnails.preset', 'thumbnail-v1') && ! config('media-studio.processing.thumbnails.enabled', true))
                    || ($preset->name === config('media-studio.processing.preview.preset', 'large-v1') && ! config('media-studio.processing.preview.enabled', true))) {
                    continue;
                }
                $recipes[$preset->name] = $preset->recipe;
            }
        }
        $inserted = 0;
        foreach ($recipes as $name => $recipe) {
            ksort($recipe);
            $encoded = json_encode($recipe, JSON_THROW_ON_ERROR);
            $new = DB::table('fms_renditions')->insertOrIgnore([
                'entry_id' => $entry->id, 'version_id' => $entry->current_version_id,
                'preset' => $name, 'recipe' => $encoded, 'recipe_hash' => hash('sha256', $encoded),
                'principal_type' => $principal->type, 'principal_id' => $principal->id,
                'state' => 'queued', 'owned' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($new !== 0) {
                app(Renditions::class)->project($entry->id, $entry->current_version_id, $name, 'queued');
                $inserted++;

                continue;
            }
            if ($replace === null) {
                continue;
            }

            $inserted += DB::table('fms_renditions')->where('version_id', $entry->current_version_id)
                ->where('preset', $name)->where('owned', true)
                ->whereIn('state', ['ready', 'failed', 'blocked'])
                ->update([
                    'replacement_recipe' => $encoded, 'replacement_retention' => $replace,
                    'principal_type' => $principal->type, 'principal_id' => $principal->id,
                    'state' => 'queued', 'failure_code' => null, 'attempts' => 0, 'dispatch_attempts' => 0,
                    'last_dispatched_at' => null, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
                ]);
        }
        if ($inserted > 0) {
            DB::afterCommit(function () use ($workspace, $entry, $principal, $inlineImagePreset, $recipes): void {
                $tenant = TenantIdentity::fromWorkspace($workspace);
                if ($inlineImagePreset !== null && isset($recipes[$inlineImagePreset]) && $entry->media_type === 'image') {
                    try {
                        $this->execute($workspace->public_id, $entry->current_version_id, $principal, $tenant, onlyPreset: $inlineImagePreset);
                    } catch (Throwable $error) {
                        report($error);
                    }
                }
                $this->dispatch($workspace->public_id, $entry->current_version_id, $principal, $tenant);
            });
        }

        return $inserted;
    }

    public function dispatch(string $workspaceId, string $versionId, Principal $principal, ?TenantIdentity $tenant = null): bool
    {
        $document = DB::table('fms_renditions')->where('version_id', $versionId)->where('owned', true)->whereNotNull('recipe')->first(['recipe', 'replacement_recipe']);
        $profile = $document ? json_decode($document->replacement_recipe ?? $document->recipe, true, 16, JSON_THROW_ON_ERROR) : [];
        if (isset($profile['document_generator'])) {
            if (! config('media-studio.processing.document_thumbnails.enabled', false)) {
                return false;
            }
            $mode = $profile['document_execution'];
            if ($mode === 'command') {
                return false;
            }
            if ($mode === 'inline') {
                try {
                    $this->execute($workspaceId, $versionId, $principal, $tenant);
                } catch (Throwable $error) {
                    report($error);
                }

                return true;
            }
            $connection = config('media-studio.processing.document_thumbnails.connection') ?? config('queue.default');
            if (config('queue.connections.'.$connection.'.driver') === 'sync') {
                return false;
            }
        }
        if (! isset($profile['document_generator']) && config('media-studio.processing.native.image_execution', 'queue') === 'command'
            && str_starts_with((string) DB::table('fms_asset_versions')->where('id', $versionId)->value('mime_type'), 'image/')) {
            return false;
        }
        $job = new GenerateThumbnail($workspaceId, $versionId, $principal->type, $principal->id, $tenant?->type, $tenant?->id);
        $job->onQueue(isset($profile['document_generator']) ? config('media-studio.processing.document_thumbnails.queue', 'document-thumbnails') : config('media-studio.processing.queue', 'default'));
        if (isset($profile['document_generator'])) {
            $job->onConnection($connection);
        }
        if (! isset($profile['document_generator']) && ($connection = config('media-studio.processing.connection'))) {
            $job->onConnection($connection);
        }
        $cooldown = now()->subSeconds(max(30, (int) config('media-studio.processing.native.dispatch_retry_seconds', 600)));
        $claimed = DB::table('fms_renditions')->where('version_id', $versionId)->where('owned', true)
            ->where('dispatch_attempts', '<', 12)
            ->where('attempts', '<', max(1, (int) config('media-studio.processing.native.max_attempts', 3)))
            ->where(fn ($q) => $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', $cooldown))
            ->where(fn ($q) => $q->whereIn('state', ['queued', 'failed'])->orWhere(fn ($q) => $q->where('state', 'processing')->where('lease_expires_at', '<=', now())))
            ->update(['last_dispatched_at' => now(), 'dispatch_attempts' => DB::raw('dispatch_attempts + 1'), 'updated_at' => now()]);
        if ($claimed === 0) {
            return false;
        }
        try {
            app(Dispatcher::class)->dispatch($job);

            return true;
        } catch (Throwable $error) {
            $rows = DB::table('fms_renditions')->where('version_id', $versionId)->where('owned', true)->where('state', 'queued')->get();
            foreach ($rows as $row) {
                DB::table('fms_renditions')->where('id', $row->id)->where('state', 'queued')
                    ->update(['state' => 'failed', 'failure_code' => 'dispatch_failed', 'updated_at' => now()]);
                if ($row->object_key === null) {
                    app(Renditions::class)->project($row->entry_id, $versionId, $row->preset, 'failed');
                }
            }
            report($error);

            return false;
        }
    }

    public function execute(string $workspaceId, string $versionId, Principal $principal, ?TenantIdentity $tenant = null, ?string $onlyPreset = null): void
    {
        $workspace = app(WorkspaceAccess::class)->resolveForWorker($principal, $workspaceId, $tenant, write: true, operation: 'files.process');
        $version = DB::table('fms_asset_versions as v')->join('fms_entries as e', 'e.id', '=', 'v.entry_id')
            ->where('v.id', $versionId)->where('e.workspace_id', $workspace->id)->whereNull('e.trashed_at')
            ->where('e.current_version_id', $versionId)->select('v.*', 'e.source_kind')->first();
        if ($version === null) {
            return;
        }
        if ($version->source_kind === 'disk') {
            app(RenditionSource::class)->verify($workspace, $principal, $version);
        } else {
            app(SourcePolicy::class)->managed($version);
        }
        if (! config('media-studio.processing.document_thumbnails.enabled', false) && ! str_starts_with($version->mime_type, 'image/') && ! VideoRenderer::supports($version->mime_type)) {
            return;
        }
        $pending = DB::table('fms_renditions')->where('version_id', $versionId)->where('owned', true)
            ->when($onlyPreset !== null, fn ($query) => $query->where('preset', $onlyPreset))
            ->where('attempts', '<', max(1, (int) config('media-studio.processing.native.max_attempts', 3)))
            ->where(fn ($q) => $q->whereIn('state', ['queued', 'failed'])->orWhere(fn ($q) => $q->where('state', 'processing')->where('lease_expires_at', '<=', now())))
            ->orderBy('id')->limit(16)->get();
        if ($pending->isEmpty()) {
            return;
        }
        $claimedRows = DB::transaction(function () use ($pending, $principal, $workspaceId, $tenant, $versionId, $version): array {
            $workspace = app(WorkspaceAccess::class)->resolveForWorker($principal, $workspaceId, $tenant, write: true, lock: true, operation: 'files.process');
            $current = DB::table('fms_entries')->where('id', $version->entry_id)->where('workspace_id', $workspace->id)
                ->where('current_version_id', $versionId)->whereNull('trashed_at')->lockForUpdate()->first();
            if ($current === null) {
                return [];
            }
            app(RenditionSource::class)->authorize($workspace, $principal, $current);
            $claimedRows = [];
            // Re-read snapshots under the workspace lock. A replacement may have
            // been scheduled since the first pending query.
            $rows = DB::table('fms_renditions')->whereIn('id', $pending->pluck('id'))->orderBy('id')->get();
            foreach ($rows as $row) {
                $token = bin2hex(random_bytes(16));
                $claimed = DB::table('fms_renditions')->where('id', $row->id)->where('owned', true)->where('attempts', $row->attempts)
                    ->where('attempts', '<', max(1, (int) config('media-studio.processing.native.max_attempts', 3)))
                    ->where(fn ($q) => $q->whereIn('state', ['queued', 'failed'])
                        ->orWhere(fn ($q) => $q->where('state', 'processing')->where('lease_expires_at', '<=', now())))
                    ->update(['state' => 'processing', 'lease_token' => $token, 'lease_expires_at' => now()->addMinutes(5),
                        'attempts' => $row->attempts + 1, 'failure_code' => null, 'updated_at' => now()]);
                if ($claimed === 1) {
                    $row->lease_token = $token;
                    $claimedRows[] = $row;
                }
            }

            return $claimedRows;
        }, 3);
        if ($claimedRows === []) {
            return;
        }
        $input = null;
        $image = null;
        $sourceError = null;
        $isVideo = VideoRenderer::supports($version->mime_type);
        $documentRaster = null;
        $generator = null;
        $documentProfile = json_decode($claimedRows[0]->replacement_recipe ?? $claimedRows[0]->recipe ?? '{}', true, 16, JSON_THROW_ON_ERROR);
        $isDocument = isset($documentProfile['document_generator']);
        $renderer = null;
        $sourceDimensions = [];
        try {
            $input = $this->readSource($version, $isVideo, $isDocument ? $documentProfile['max_source_bytes'] : null);
            $path = stream_get_meta_data($input)['uri'];
            if ($isDocument) {
                if (! config('media-studio.processing.document_thumbnails.enabled', false)) {
                    throw new RuntimeException('document_thumbnails_disabled');
                }
                $generator = app($documentProfile['document_generator']);
                if (! $generator instanceof ThumbnailGenerator) {
                    throw new RuntimeException('document_renderer_unavailable');
                }
                if (! $generator instanceof EncodedThumbnailGenerator) {
                    $documentRaster = $generator->rasterize($path, $documentProfile);
                    if (! is_resource($documentRaster) || fstat($documentRaster)['size'] > $documentProfile['max_raster_bytes']) {
                        throw new RuntimeException('document_output_too_large');
                    }
                    $renderer = $this->autoRenderer('image/png', $claimedRows);
                    $image = $renderer->open(stream_get_meta_data($documentRaster)['uri'], 16777216);
                }
            } elseif ($isVideo) {
                $renderer = app(VideoRenderer::class);
            } else {
                $renderer = match (config('media-studio.processing.native.driver', 'auto')) {
                    'auto' => $this->autoRenderer($version->mime_type, $claimedRows),
                    'gd' => app(GdImageRenderer::class),
                    'imagick' => app(ImagickImageRenderer::class),
                    default => throw new RuntimeException('encoder_unavailable'),
                };
                $image = $renderer->open($path, (int) config('media-studio.processing.thumbnails.max_pixels', 16000000));
                $sourceDimensions = $image instanceof \GdImage
                    ? ['width' => imagesx($image), 'height' => imagesy($image)]
                    : ['width' => $image->getImageWidth(), 'height' => $image->getImageHeight()];
            }
        } catch (Throwable $error) {
            $sourceError = $error;
        }
        $lastError = null;
        // Largest requested bounds first; ties prefer the highest configured quality.
        // Only metadata is cached, never encoded buffers or decoded extra images.
        if (! $isVideo && ! $isDocument && config('media-studio.processing.native.deduplicate', true)) {
            usort($claimedRows, function ($a, $b): int {
                $a = json_decode($a->replacement_recipe ?? $a->recipe ?? '{}', true);
                $b = json_decode($b->replacement_recipe ?? $b->recipe ?? '{}', true);

                return [max($b['width'] ?? 0, $b['height'] ?? 0), $b['quality'] ?? 0] <=> [max($a['width'] ?? 0, $a['height'] ?? 0), $a['quality'] ?? 0];
            });
        }
        $encodedVariants = [];
        try {
            foreach ($claimedRows as $row) {
                $token = $row->lease_token;
                $query = fn () => DB::table('fms_renditions')->where('id', $row->id)->where('owned', true);
                $output = null;
                $diskName = $version->disk;
                $disk = null;
                $key = trim($workspace->storage_root, '/').'/'.$workspace->public_id.'/renditions/'.$versionId.'/'.$token.'/'.$row->preset;
                $published = false;
                try {
                    if ($sourceError !== null) {
                        throw $sourceError;
                    }
                    $encodedRecipe = $row->replacement_recipe ?? $row->recipe;
                    $recipe = $encodedRecipe === null ? ImagePresets::configured()[$row->preset]->recipe : json_decode($encodedRecipe, true, 8, JSON_THROW_ON_ERROR);
                    $diskName = $recipe['disk'] ?? ($version->source_kind === 'disk' ? $workspace->disk : $version->disk);
                    $disk = Storage::disk($diskName);
                    $reuseKey = null;
                    if (! $isVideo && ! $isDocument && config('media-studio.processing.native.deduplicate', true) && ($recipe['deduplicate'] ?? true)) {
                        $preset = new ImagePreset($row->preset, $recipe);
                        $geometry = ImageGeometry::forPreset($sourceDimensions['width'], $sourceDimensions['height'], $preset);
                        $settings = $recipe;
                        unset($settings['width'], $settings['height'], $settings['quality'], $settings['deduplicate']);
                        ksort($settings);
                        $reuseKey = hash('sha256', json_encode([$geometry, $settings, $diskName], JSON_THROW_ON_ERROR));
                    }
                    $shared = $reuseKey === null ? null : ($encodedVariants[$reuseKey] ?? null);
                    $effectiveQuality = $recipe['quality'] ?? 0;
                    if ($shared !== null && $shared['quality'] >= $recipe['quality']) {
                        $effectiveQuality = $shared['quality'];
                        $key = $shared['key'];
                        $dimensions = $shared['dimensions'];
                        $mime = $shared['mime'];
                        $size = $shared['size'];
                        $sha = $shared['sha'];
                    } else {
                        $output = $generator instanceof EncodedThumbnailGenerator
                            ? $generator->rasterize($path, $recipe)
                            : tmpfile();
                        if (! is_resource($output)) {
                            throw new RuntimeException('encoding_failed');
                        }
                        if ($generator instanceof EncodedThumbnailGenerator) {
                            $info = @getimagesize(stream_get_meta_data($output)['uri']);
                            if ($info === false || $info[0] < 1 || $info[1] < 1
                                || $info[0] > $recipe['width'] || $info[1] > $recipe['height']) {
                                throw new RuntimeException('encoded_image_invalid');
                            }
                            $dimensions = ['width' => $info[0], 'height' => $info[1]];
                            $mime = (new ImagePreset($row->preset, DocumentThumbnails::imageRecipe($recipe)))->mime();
                        } elseif ($renderer instanceof VideoRenderer) {
                            $dimensions = $renderer->render($path, $version->mime_type, $recipe, $output);
                            $mime = $recipe['mime'];
                        } else {
                            $preset = new ImagePreset($row->preset, $isDocument ? DocumentThumbnails::imageRecipe($recipe) : $recipe);
                            $dimensions = $renderer->render($image, $preset, $output);
                            $mime = $preset->mime();
                        }
                        if (! $renderer instanceof VideoRenderer) {
                            app(EncodedImageVerifier::class)->verify($output, $mime, $dimensions);
                        }
                        $size = fstat($output)['size'];
                        if ($isDocument && $size > $documentProfile['max_raster_bytes']) {
                            throw new RuntimeException('document_output_too_large');
                        }
                        $sha = hash_file('sha256', stream_get_meta_data($output)['uri']);
                        rewind($output);
                        (new StreamObjectStore(new LaravelObjectStore($disk)))->write($key, $output);
                    }
                    if ($version->source_kind === 'disk') {
                        $freshWorkspace = app(WorkspaceAccess::class)->resolveForWorker($principal, $workspaceId, $tenant, write: true, operation: 'files.process');
                        app(RenditionSource::class)->verify($freshWorkspace, $principal, $version);
                    }
                    $published = DB::transaction(function () use ($principal, $workspaceId, $tenant, $version, $versionId, $row, $query, $token, $key, $mime, $size, $sha, $dimensions, $sourceDimensions, $diskName): bool {
                        $workspace = app(WorkspaceAccess::class)->resolveForWorker($principal, $workspaceId, $tenant, write: true, lock: true, operation: 'files.process');
                        $current = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $version->entry_id)
                            ->where('current_version_id', $versionId)->whereNull('trashed_at')->lockForUpdate()->first();
                        if ($current === null) {
                            $query()->where('lease_token', $token)->update(['state' => 'superseded', 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);

                            return false;
                        }
                        app(RenditionSource::class)->authorize($workspace, $principal, $current);
                        $values = ['state' => 'ready', 'disk' => $diskName, 'object_key' => $key, 'mime_type' => $mime,
                            'size_bytes' => $size, 'sha256' => $sha, ...$dimensions, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()];
                        if ($row->replacement_recipe !== null) {
                            $values += [
                                'recipe' => $row->replacement_recipe, 'recipe_hash' => hash('sha256', $row->replacement_recipe),
                                'replacement_recipe' => null, 'replacement_retention' => null,
                            ];
                        }
                        if ($query()->where('lease_token', $token)->where('lease_expires_at', '>', now())->where('state', 'processing')->update($values) !== 1) {
                            return false;
                        }
                        if ($row->replacement_recipe !== null && $row->object_key !== null) {
                            DB::table('fms_retired_renditions')->insert([
                                'workspace_id' => $workspace->id, 'disk' => $row->disk, 'object_key' => $row->object_key,
                                'retired_at' => now(),
                                'delete_after' => $row->replacement_retention === 'prune'
                                    ? now()->addSeconds(max(3600, (int) config('media-studio.processing.native.retirement_grace_seconds', 86400))) : null,
                            ]);
                        }
                        app(Renditions::class)->project($current->id, $versionId, $row->preset, 'ready', (object) ['preset' => $row->preset, ...$values]);
                        if ($sourceDimensions !== []) {
                            DB::table('fms_entries')->where('id', $current->id)->update($sourceDimensions);
                        }
                        $unfinished = DB::table('fms_renditions')->where('version_id', $versionId)->where('owned', true)
                            ->whereIn('state', ['queued', 'processing', 'failed'])->exists();
                        if (! $unfinished) {
                            app(OriginalRetention::class)->promote($workspace, $current, $version);
                            app(ActivityLog::class)->record($workspace, $principal, $current, 'processed', $versionId);
                        }

                        return true;
                    }, 3);
                    if ($published && $reuseKey !== null) {
                        $encodedVariants[$reuseKey] = ['key' => $key, 'dimensions' => $dimensions, 'mime' => $mime,
                            'size' => $size, 'sha' => $sha, 'quality' => $effectiveQuality];
                    }
                } catch (Throwable $error) {
                    $known = ['encoder_unavailable', 'source_too_large', 'source_unavailable', 'source_changed', 'unsupported_dimensions', 'unsupported_image', 'rotation_failed', 'encoding_failed', 'image_memory_limit', 'output_too_large', 'metadata_requires_imagick'];
                    $failed = $query()->where('lease_token', $token)->update(['state' => 'failed', 'lease_token' => null, 'lease_expires_at' => null,
                        'failure_code' => in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'processing_failed', 'updated_at' => now()]);
                    if ($failed === 1 && $row->object_key === null) {
                        app(Renditions::class)->project($row->entry_id, $versionId, $row->preset, 'failed');
                    }
                    $lastError = $error;
                } finally {
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    if ($disk !== null && ! $published && ! DB::table('fms_renditions')->where('disk', $diskName)->where('object_key', $key)->exists()
                        && ! DB::table('fms_asset_versions')->where('disk', $diskName)->where('object_key', $key)->exists()) {
                        try {
                            $disk->delete($key);
                        } catch (Throwable $cleanupError) {
                            report($cleanupError);
                        }
                    }
                }
            }
        } finally {
            if ($image instanceof \Imagick) {
                $image->clear();
            }
            $image = null;
            if (is_resource($documentRaster)) {
                fclose($documentRaster);
            }
            if (is_resource($input)) {
                fclose($input);
            }
        }
        if ($lastError !== null) {
            throw $lastError;
        }
    }

    private function autoRenderer(string $mimeType, array $rows): GdImageRenderer|ImagickImageRenderer
    {
        if (! extension_loaded('gd')) {
            return app(ImagickImageRenderer::class);
        }

        $inputFormat = substr($mimeType, strlen('image/'));
        if (! extension_loaded('imagick')) {
            return app(GdImageRenderer::class);
        }
        if (! GdImageRenderer::supports($inputFormat) && \Imagick::queryFormats(strtoupper($inputFormat)) !== []) {
            return app(ImagickImageRenderer::class);
        }

        $formats = [$inputFormat];
        $preserveMetadata = false;
        foreach ($rows as $row) {
            $recipe = json_decode($row->replacement_recipe ?? $row->recipe ?? '{}', true);
            $formats[] = $recipe['format'] ?? ImagePresets::configured()[$row->preset]->recipe['format'] ?? 'webp';
            $preserveMetadata = $preserveMetadata || ($recipe['strip_metadata'] ?? true) === false;
        }
        $formats = array_unique($formats);
        $missingFromGd = array_filter($formats, fn (string $format): bool => ! GdImageRenderer::supports($format));
        if (($preserveMetadata || $missingFromGd !== []) && array_reduce($formats, fn (bool $available, string $format): bool => $available && \Imagick::queryFormats(strtoupper($format)) !== [], true)) {
            return app(ImagickImageRenderer::class);
        }

        return app(GdImageRenderer::class);
    }

    private function readSource(object $version, bool $video, ?int $documentLimit = null): mixed
    {
        $maxBytes = $video
            ? max(1048576, min(1073741824, (int) config('media-studio.processing.video.max_source_bytes', 268435456)))
            : (int) config('media-studio.processing.thumbnails.max_source_bytes', 26214400);
        $maxBytes = $documentLimit ?? $maxBytes;
        if ($version->size_bytes > $maxBytes) {
            throw new RuntimeException('source_too_large');
        }
        $input = tmpfile();
        $source = null;
        try {
            $source = Storage::disk($version->disk)->readStream($version->object_key);
            if (! is_resource($input) || ! is_resource($source)) {
                throw new RuntimeException('source_unavailable');
            }
            $bytes = stream_copy_to_stream($source, $input, $maxBytes + 1);
            if ($bytes === false || $bytes > $maxBytes) {
                throw new RuntimeException('source_too_large');
            }
            if ($bytes !== (int) $version->size_bytes || ($version->sha256 !== null
                && ! hash_equals($version->sha256, hash_file('sha256', stream_get_meta_data($input)['uri'])))) {
                throw new RuntimeException('source_changed');
            }

            return $input;
        } catch (Throwable $error) {
            if (is_resource($input)) {
                fclose($input);
            }
            throw $error;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }
}
