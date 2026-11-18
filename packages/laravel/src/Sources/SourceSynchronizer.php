<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sources;

use Aws\Exception\AwsException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Integrations\Spatie\SourceIndexer;
use UseFlex\MediaStudio\Integrations\Spatie\SourceMount;

/** Scheduled, fenced index maintenance. Never deletes an external storage object. */
final class SourceSynchronizer
{
    public function run(string $key, int $limit = 60): array
    {
        abort_unless($limit >= 1 && $limit <= 120, 422);
        [$config, $principal, $workspace, $mount, $revision] = $this->context($key);
        $id = hash('sha256', json_encode([$key, $config['workspace'], $config['kind'], $config['mount'], $principal->key()], JSON_THROW_ON_ERROR));
        $lease = (string) Str::ulid();
        $state = DB::transaction(function () use ($id, $lease, $workspace, $revision): ?object {
            DB::table('fms_source_syncs')->insertOrIgnore(['id' => $id, 'workspace_id' => $workspace->id, 'revision' => $revision]);
            $row = DB::table('fms_source_syncs')->where('id', $id)->lockForUpdate()->first();
            if (($row->lease_until !== null && now()->lt($row->lease_until)) || ($row->revision === $revision && $row->next_run_at !== null && now()->lt($row->next_run_at))) {
                return null;
            }
            $reset = $row->revision !== $revision;
            $changes = ['lease' => $lease, 'lease_until' => now()->addSeconds(300), 'revision' => $revision];
            if ($reset) {
                $changes += ['after_id' => 0, 'phase' => 'check', 'discovery_cursor' => null];
            }
            DB::table('fms_source_syncs')->where('id', $id)->update($changes);

            return DB::table('fms_source_syncs')->where('id', $id)->first();
        }, 3);
        if ($state === null) {
            return ['state' => 'waiting', 'checked' => 0];
        }
        $fence = function () use ($key, $revision, $id, $lease): void {
            [, , , , $current] = $this->context($key);
            abort_unless(hash_equals($revision, $current), 409, 'Source configuration changed.');
            $row = DB::table('fms_source_syncs')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row !== null && $row->lease === $lease && now()->lt($row->lease_until), 409, 'Source synchronization lease expired.');
        };
        try {
            if ($state->phase === 'discover') {
                return $this->discover($key, $config, $principal, $workspace, $mount, $state, $lease, $limit, $fence);
            }
            $table = $config['kind'] === 'disk' ? 'fms_disk_sources' : 'fms_spatie_sources';
            $query = DB::table($table.' as s')->join('fms_entries as e', 'e.id', '=', 's.entry_id')
                ->where('s.workspace_id', $workspace->id)->where('s.mount_key', $config['mount'])
                ->where('e.kind', 'file')->where('s.id', '>', $state->after_id)->orderBy('s.id')->limit($limit)->select('s.*', 'e.size_bytes', 'e.current_version_id');
            $rows = $query->get();
            if ($config['kind'] === 'spatie') {
                DB::transaction(function () use ($rows, $fence, $workspace, $mount, $principal, $table, $config): void {
                    $fence();
                    app(SourceIndexer::class)->refreshForWorker($workspace, $mount, $principal, $rows, fn ($mapping) => $this->missing($table, $mapping, $config));
                }, 3);
            } else {
                foreach ($rows as $mapping) {
                    // Changed mount roots are never evidence that old source objects were deleted.
                    if ($mapping->mount_revision !== $mount->revision()) {
                        continue;
                    }
                    try {
                        $metadata = DiskObjectMetadata::read(Storage::disk($mount->config['disk']), $mount->path($mapping->object_key));
                    } catch (\Throwable $error) {
                        if (! $this->isMissing($error)) {
                            throw $error;
                        }
                        DB::transaction(function () use ($fence, $table, $mapping, $config): void {
                            $fence();
                            $this->missing($table, $mapping, $config);
                        }, 3);

                        continue;
                    }
                    if ((int) $mapping->modified_at === $metadata['modified'] && (int) $mapping->size_bytes === $metadata['size']) {
                        DB::transaction(function () use ($fence, $table, $mapping): void {
                            $fence();
                            $current = DB::table('fms_entries')->where('id', $mapping->entry_id)->lockForUpdate()->value('current_version_id');
                            if ($current === $mapping->current_version_id) {
                                DB::table($table)->where('id', $mapping->id)->update(['missing_since' => null, 'missing_at' => null, 'missing_checks' => 0]);
                            }
                        }, 3);

                        continue;
                    }
                    // Reuse authoritative import logic, retaining identity and regenerating versions only on change.
                    app(DiskIndexer::class)->importPage($principal, $config['workspace'], $mount->key, [$mapping->object_key], true, $fence);
                }
            }
            DB::transaction(function () use ($fence, $state, $lease, $rows): void {
                $fence();
                DB::table('fms_source_syncs')->where('id', $state->id)->where('lease', $lease)->update([
                    'after_id' => $rows->last()?->id ?? $state->after_id,
                    'phase' => $rows->isEmpty() ? 'discover' : 'check', 'lease' => null, 'lease_until' => null, 'next_run_at' => null, 'failed_at' => null,
                ]);
            }, 3);

            return ['state' => 'checking', 'checked' => $rows->count()];
        } catch (\Throwable $error) {
            DB::table('fms_source_syncs')->where('id', $id)->where('lease', $lease)->update(['lease' => null, 'lease_until' => null, 'failed_at' => now(), 'next_run_at' => now()->addSeconds(60)]);
            throw $error;
        }
    }

    private function missing(string $table, object $mapping, array $config): void
    {
        $current = DB::table($table)->where('id', $mapping->id)->lockForUpdate()->first();
        // A concurrent manual import must win over an observation of its older source version.
        if ($current === null || ($current->mount_revision ?? $current->source_revision) !== ($mapping->mount_revision ?? $mapping->source_revision)) {
            return;
        }
        $version = DB::table('fms_entries')->where('id', $mapping->entry_id)->lockForUpdate()->value('current_version_id');
        if ($version !== $mapping->current_version_id) {
            return;
        }
        $since = $current->missing_since ?? now();
        $checks = $current->missing_checks + 1;
        $confirmed = $checks >= 2 && now()->gte(Carbon::parse($since)->addSeconds($config['missing_grace_seconds'] ?? 300));
        DB::table($table)->where('id', $current->id)->update(['missing_since' => $since, 'missing_checks' => min($checks, 1000000), 'missing_at' => $confirmed ? ($current->missing_at ?? now()) : null]);
    }

    private function discover(string $key, array $config, Principal $principal, object $workspace, DiskMount|SourceMount $mount, object $state, string $lease, int $limit, callable $fence): array
    {
        $cursor = $state->discovery_cursor === null ? null : Crypt::decryptString($state->discovery_cursor);
        $next = null;
        $processed = 0;
        if ($mount instanceof SourceMount) {
            DB::transaction(function () use ($principal, $config, $mount, $cursor, $limit, $fence, &$next, &$processed): void {
                $fence();
                $result = app(SourceIndexer::class)->syncPage($principal, $config['workspace'], $mount->key, $cursor, $limit, $fence, true);
                $next = $result['nextCursor'];
                $processed = $result['processed'];
            }, 3);
        } elseif (($config['discover'] ?? true) && Storage::disk($mount->config['disk']) instanceof AwsS3V3Adapter) {
            $disk = Storage::disk($mount->config['disk']);
            $prefix = rtrim($disk->path(trim((string) ($mount->config['prefix'] ?? ''), '/')), '/');
            $prefix = $prefix === '' ? '' : $prefix.'/';
            $request = ['Bucket' => $disk->getConfig()['bucket'], 'Prefix' => $prefix, 'MaxKeys' => $limit];
            if ($cursor !== null) {
                $request['ContinuationToken'] = $cursor;
            }
            $result = $disk->getClient()->listObjectsV2($request);
            $next = ($result['IsTruncated'] ?? false) ? ($result['NextContinuationToken'] ?? null) : null;
            abort_if(($result['IsTruncated'] ?? false) && (! is_string($next) || $next === '' || $next === $cursor), 502);
            $objects = $result['Contents'] ?? [];
            abort_unless(count($objects) <= $limit, 502);
            foreach ($objects as $object) {
                $objectKey = (string) ($object['Key'] ?? '');
                abort_unless(str_starts_with($objectKey, $prefix) && str_starts_with($objectKey, $disk->path('')), 502);
                if (str_ends_with($objectKey, '/')) {
                    continue;
                }
                $path = $mount->path(substr($objectKey, strlen($disk->path(''))));
                try {
                    app(DiskIndexer::class)->importPage($principal, $config['workspace'], $mount->key, [$path], true, $fence);
                } catch (\Throwable $error) {
                    if (! $this->isMissing($error)) {
                        throw $error;
                    }
                }
                $processed++;
            }
        }
        DB::transaction(function () use ($fence, $state, $lease, $next, $config): void {
            $fence();
            DB::table('fms_source_syncs')->where('id', $state->id)->where('lease', $lease)->update([
                'lease' => null, 'lease_until' => null, 'failed_at' => null,
                'discovery_cursor' => $next === null ? null : Crypt::encryptString($next),
                'after_id' => $next === null ? 0 : $state->after_id, 'phase' => $next === null ? 'check' : 'discover',
                'next_run_at' => $next === null ? now()->addSeconds($config['interval_seconds'] ?? 300) : null,
                'completed_at' => $next === null ? now() : $state->completed_at,
            ]);
        }, 3);

        return ['state' => $next === null ? 'complete' : 'discovering', 'checked' => $processed];
    }

    private function context(string $key): array
    {
        abort_unless(preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $key), 422);
        $config = config('media-studio.sources.sync.targets.'.$key);
        abort_unless(is_array($config) && ($config['enabled'] ?? true) === true && in_array($config['kind'] ?? null, ['disk', 'spatie'], true), 422, 'Configure an enabled synchronization target.');
        abort_unless(is_int($config['interval_seconds'] ?? 300) && ($config['interval_seconds'] ?? 300) >= 1 && ($config['interval_seconds'] ?? 300) <= 86400, 422);
        abort_unless(is_int($config['missing_grace_seconds'] ?? 300) && ($config['missing_grace_seconds'] ?? 300) >= 0 && ($config['missing_grace_seconds'] ?? 300) <= 604800, 422);
        $principal = new Principal((string) ($config['principal_type'] ?? ''), (string) ($config['principal_id'] ?? ''));
        abort_unless(is_string(config('media-studio.principals.'.$principal->type)) && $principal->id !== '' && strlen($principal->id) <= 191, 422);
        $identity = DB::table('fms_workspaces')->where('public_id', $config['workspace'] ?? '')->first(['tenant_type', 'tenant_id', 'tenant_key']);
        abort_if($identity === null, 404);
        $workspace = app(WorkspaceAccess::class)->resolveForWorker($principal, $config['workspace'], TenantIdentity::fromWorkspace($identity), write: true, operation: 'sources.sync');
        abort_unless(in_array($workspace->role, ['owner', 'admin'], true), 403);
        $mount = $config['kind'] === 'disk' ? DiskMount::find($config['mount'] ?? '') : SourceMount::find($config['mount'] ?? '');
        abort_if($mount === null, 404);
        if ($mount instanceof DiskMount) {
            abort_unless($mount->allows($workspace, $principal), 403);
        } else {
            $mount->query($workspace, $principal);
        }
        $diskConfig = $mount instanceof DiskMount ? Storage::disk($mount->config['disk'])->getConfig() : [];
        $revision = hash('sha256', json_encode([$config['workspace'], $principal->key(), $config['kind'], $config['mount'], $config['revision'] ?? 1, $mount instanceof DiskMount ? $mount->revision() : $mount->config['revision'] ?? 1, array_intersect_key($diskConfig, array_flip(['driver', 'root', 'bucket', 'endpoint', 'region']))], JSON_THROW_ON_ERROR));

        return [$config, $principal, $workspace, $mount, $revision];
    }

    private function isMissing(\Throwable $error): bool
    {
        return ($error instanceof HttpException && $error->getStatusCode() === 404)
            || ($error instanceof AwsException && $error->getStatusCode() === 404 && in_array($error->getAwsErrorCode(), ['NoSuchKey', 'NotFound', 'NoSuchObject'], true));
    }
}
