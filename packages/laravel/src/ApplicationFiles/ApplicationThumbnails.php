<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use UseFlex\MediaCore\Images\EncodedImageVerifier;
use UseFlex\MediaCore\Images\GdImageRenderer;
use UseFlex\MediaCore\Images\ImagickImageRenderer;
use UseFlex\MediaCore\Storage\StreamObjectStore;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Storage\LaravelObjectStore;

final class ApplicationThumbnails
{
    public function __construct(private readonly ApplicationCatalog $catalog) {}

    /** @return array<int, string> Cache keys keyed by application entry ID. */
    public function keys(ApplicationThumbnailProfile $profile, array $rows, array $sources): array
    {
        $identity = $profile->identity();
        $keys = [];
        foreach ($rows as $row) {
            if ($row->kind !== 'file' || ($sources[$row->source_key]->config['generate_thumbnails'] ?? true) !== true) {
                continue;
            }
            $data = json_decode($row->descriptor, true, 16, JSON_THROW_ON_ERROR);
            if (isset($data['thumbnail']) || ! in_array($data['mime'] ?? null, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)
                || ($data['size'] ?? 0) < 1 || $data['size'] > $profile->sourceBytes) {
                continue;
            }
            $keys[$row->id] = hash('sha256', json_encode([$row->public_id, $row->source_revision, $row->descriptor, $identity], JSON_THROW_ON_ERROR));
        }

        return $keys;
    }

    public function states(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return $this->cache()->whereIn('cache_key', array_values($keys))->where('expires_at', '>', now())
            ->pluck('state', 'cache_key')->all();
    }

    public function request(ApplicationThumbnailProfile $profile, array $keys, array $rows, array $sources, Principal $principal, bool $runInline = false): void
    {
        if ($keys === [] || (! $runInline && ! $profile->usesWorker())) {
            return;
        }
        $jobs = DB::transaction(function () use ($profile, $keys, $rows, $sources, $principal): array {
            $budget = $this->budget()->lockForUpdate()->first();
            $remaining = min(16, $profile->budgetEntries - $budget->entries, intdiv(max(0, $profile->budgetBytes - $budget->bytes), $profile->outputBytes));
            if ($remaining <= 0) {
                if (! $budget->pressure && $profile->budgetEntries > 0 && $profile->budgetBytes >= $profile->outputBytes) {
                    $this->budget()->update(['pressure' => true]);
                }

                return [];
            }
            $pending = $this->cache()->whereIn('state', ['queued', 'processing'])->count();
            $remaining = min($remaining, $profile->maxPending - $pending);
            if ($remaining <= 0) {
                return [];
            }
            $existing = $this->cache()->whereIn('cache_key', array_values($keys))->pluck('cache_key')->flip();
            $records = [];
            $jobs = [];
            foreach ($rows as $row) {
                $key = $keys[$row->id] ?? null;
                if ($key === null || $existing->has($key)) {
                    continue;
                }
                $token = (string) Str::ulid();
                $records[] = ['cache_key' => $key, 'token' => $token, 'disk' => $profile->disk,
                    'object_key' => $profile->prefix.'/'.substr($key, 0, 2).'/'.$key.'/'.$token,
                    'bytes' => $profile->outputBytes, 'state' => 'queued', 'expires_at' => now()->addMinutes(15)];
                $jobs[] = (new GenerateApplicationThumbnail($key, $token, (int) $row->id, $row->source_key, $principal, $sources[$row->source_key]->tenant))
                    ->onConnection($profile->connection)->onQueue($profile->queue);
                if (count($records) >= $remaining) {
                    break;
                }
            }
            if ($records !== []) {
                $this->cache()->insert($records);
                $this->budget()->incrementEach(['entries' => count($records), 'bytes' => count($records) * $profile->outputBytes]);
            }

            return $jobs;
        }, 3);

        foreach ($jobs as $job) {
            try {
                if ($runInline) {
                    $this->generate($job);
                } else {
                    app(Dispatcher::class)->dispatch($job);
                }
            } catch (Throwable $error) {
                $this->claim($job)->where('state', 'queued')->update(['expires_at' => now()]);
                report($error);
            }
        }
    }

    public function generate(GenerateApplicationThumbnail $job): void
    {
        $claim = $this->claim($job);
        if ((clone $claim)->where('state', 'queued')->where('expires_at', '>', now())->update(['state' => 'processing', 'expires_at' => now()->addMinutes(5)]) !== 1) {
            return;
        }
        $input = null;
        $output = null;
        try {
            $profile = ApplicationThumbnailProfile::configured();
            $original = $this->authorizedOriginal($job, $profile);
            $input = tmpfile();
            $output = tmpfile();
            if (! is_resource($input) || ! is_resource($output)) {
                throw new RuntimeException('Could not allocate thumbnail streams.');
            }
            $stream = Storage::disk($original->disk)->readStream($original->object_key);
            if (! is_resource($stream)) {
                throw new RuntimeException('Could not open thumbnail source.');
            }
            try {
                $bytes = stream_copy_to_stream($stream, $input, $profile->sourceBytes + 1);
            } finally {
                fclose($stream);
            }
            if ($bytes === false || $bytes > $profile->sourceBytes || $bytes !== (int) $original->size_bytes) {
                throw new RuntimeException('Thumbnail source changed or exceeded its byte limit.');
            }
            $renderer = $this->renderer($profile, $original->mime_type);
            $image = $renderer->open(stream_get_meta_data($input)['uri'], $profile->sourcePixels);
            try {
                $dimensions = $renderer->render($image, $profile->preset, $output);
            } finally {
                if ($image instanceof \Imagick) {
                    $image->clear();
                }
                unset($image);
            }
            app(EncodedImageVerifier::class)->verify($output, $profile->preset->mime(), $dimensions);
            $size = fstat($output)['size'];
            if ($size < 1 || $size > $profile->outputBytes) {
                throw new RuntimeException('Thumbnail exceeded its output byte limit.');
            }
            $this->authorizedOriginal($job, ApplicationThumbnailProfile::configured());

            // Only this entry is locked during upload. The admission ledger remains available.
            DB::transaction(function () use ($job, $claim, $profile, $output, $size): void {
                $row = (clone $claim)->where('state', 'processing')->where('expires_at', '>', now())->lockForUpdate()->first();
                if ($row === null) {
                    return;
                }
                (new StreamObjectStore(new LaravelObjectStore(Storage::disk($row->disk))))->write($row->object_key, $output);
                $this->authorizedOriginal($job, ApplicationThumbnailProfile::configured());
                (clone $claim)->update(['state' => 'ready', 'bytes' => $size, 'expires_at' => now()->addMinutes($profile->ttlMinutes)]);
                $this->budget()->decrement('bytes', $row->bytes - $size);
            });
        } catch (Throwable $error) {
            // Retain the reservation until cleanup, including when a storage write succeeded but publication failed.
            (clone $claim)->where('state', 'processing')->update(['state' => 'failed', 'expires_at' => now()->addMinutes(15)]);
            if (! $error instanceof HttpExceptionInterface) {
                report($error);
            }
        } finally {
            foreach ([$input, $output] as $resource) {
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }
        }
    }

    public function content(ApplicationThumbnailProfile $profile, CatalogSource $source, Principal $principal, object $entry): object
    {
        $key = $this->keys($profile, [$entry], [$source->key => $source])[$entry->id] ?? null;
        abort_if($key === null, 404);
        $row = $this->cache()->where('cache_key', $key)->where('state', 'ready')->where('expires_at', '>', now())->first();
        abort_if($row === null, 404);
        $this->catalog->content($source, $principal, $entry, 'original', missingThumbnail: true);

        return (object) ['disk' => $row->disk, 'object_key' => $row->object_key, 'mime_type' => $profile->preset->mime(),
            'size_bytes' => $row->bytes, 'name' => pathinfo($entry->name, PATHINFO_FILENAME).'.'.$profile->preset->recipe['format']];
    }

    public function prune(int $limit): int
    {
        $candidates = $this->cache()->where('expires_at', '<=', now())->orderBy('expires_at')->orderBy('cache_key')->limit($limit)->get(['cache_key', 'token']);
        $profile = ApplicationThumbnailProfile::configured();
        $budget = $profile === null ? null : $this->budget()->first();
        $evict = $budget !== null && $budget->pressure && ($budget->entries >= $profile->budgetEntries || $budget->bytes + $profile->outputBytes > $profile->budgetBytes);
        if ($evict && $candidates->count() < $limit) {
            $candidates = $candidates->concat($this->cache()->where('state', 'ready')->where('expires_at', '>', now())
                ->orderBy('expires_at')->orderBy('cache_key')->limit($limit - $candidates->count())->get(['cache_key', 'token']));
        }
        $removed = 0;
        foreach ($candidates as $candidate) {
            try {
                $removed += (int) DB::transaction(function () use ($candidate, $evict): bool {
                    $query = $this->cache()->where('cache_key', $candidate->cache_key)->where('token', $candidate->token)->where(fn (Builder $q) => $q->where('expires_at', '<=', now())->when($evict, fn (Builder $q) => $q->orWhere('state', 'ready')));
                    $row = (clone $query)->lockForUpdate()->first();
                    if ($row === null) {
                        return false;
                    }
                    if ($row->state !== 'queued' && ! Storage::disk($row->disk)->delete($row->object_key)) {
                        $query->update(['state' => 'failed', 'expires_at' => now()->addMinutes(5)]);

                        return false;
                    }
                    $query->delete();
                    $this->budget()->decrementEach(['bytes' => $row->bytes, 'entries' => 1]);

                    return true;
                });
            } catch (Throwable $error) {
                $this->cache()->where('cache_key', $candidate->cache_key)->where('token', $candidate->token)
                    ->where('expires_at', '<=', now())->update(['state' => 'failed', 'expires_at' => now()->addMinutes(5)]);
                report($error);
            }
        }

        if ($budget?->pressure) {
            $this->budget()->where('entries', '<', $profile->budgetEntries)
                ->where('bytes', '<=', $profile->budgetBytes - $profile->outputBytes)->update(['pressure' => false]);
        }

        return $removed;
    }

    private function authorizedOriginal(GenerateApplicationThumbnail $job, ?ApplicationThumbnailProfile $profile): object
    {
        abort_unless($profile !== null && config('media-studio.application_files.enabled') === true, 404);
        app(WorkspaceAccess::class)->authorizeTenantForWorker($job->principal, $job->tenant);
        $permissions = app(StudioPermissions::class);
        $actor = $permissions->actor($job->principal);
        $permissions->authorize($actor, 'application-files.view');
        $source = CatalogSource::find($job->source, $job->tenant);
        abort_unless($source->allows($job->principal, $actor), 404);
        $row = $this->catalog->query($source)->useWritePdo()->find($job->entry);
        abort_unless($row !== null && ($this->keys($profile, [$row], [$source->key => $source])[$row->id] ?? null) === $job->key, 404);

        return $this->catalog->content($source, $job->principal, $row, 'original', missingThumbnail: true);
    }

    private function renderer(ApplicationThumbnailProfile $profile, string $mime): GdImageRenderer|ImagickImageRenderer
    {
        $useImagick = $profile->driver === 'imagick' || ($profile->driver === 'auto' && extension_loaded('imagick')
            && (! GdImageRenderer::supports(substr($mime, 6)) || ! GdImageRenderer::supports($profile->preset->recipe['format'])));

        return $useImagick ? app(ImagickImageRenderer::class) : app(GdImageRenderer::class);
    }

    private function claim(GenerateApplicationThumbnail $job): Builder
    {
        return $this->cache()->where('cache_key', $job->key)->where('token', $job->token);
    }

    private function cache(): Builder
    {
        return DB::table('fms_application_thumbnails')->useWritePdo();
    }

    private function budget(): Builder
    {
        return DB::table('fms_application_thumbnail_budget');
    }
}
