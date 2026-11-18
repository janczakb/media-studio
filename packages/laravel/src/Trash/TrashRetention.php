<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Trash;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\ActivityLog;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

/** Tombstones and immutable object receipts bridge SQL and non-transactional disks. */
final class TrashRetention
{
    public function __construct(private WorkspaceAccess $access) {}

    public function candidates(object $workspace, int $limit, ?string $afterDate = null, int $afterId = 0): Builder
    {
        return DB::table('fms_entries')->where('workspace_id', $workspace->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fms_entries as child')->whereColumn('child.parent_id', 'fms_entries.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fms_usages as usage')->whereColumn('usage.entry_id', 'fms_entries.id'))
            ->where('source_kind', 'managed')->whereNull('purging_at')->where('trashed_at', '<=', $this->cutoff())
            ->when($afterDate !== null, fn (Builder $query) => $query->where(fn (Builder $after) => $after
                ->where('trashed_at', '>', $afterDate)
                ->orWhere(fn (Builder $sameDate) => $sameDate->where('trashed_at', $afterDate)->where('id', '>', $afterId))))
            ->orderBy('trashed_at')->orderBy('id')->limit(min(100, max(1, $limit)));
    }

    public function maxAttempts(): int
    {
        return min(100, max(3, (int) config('media-studio.trash.max_attempts', 12)));
    }

    public function cutoff(): Carbon
    {
        return now()->subDays($this->days());
    }

    public function days(): int
    {
        return min(36500, max(1, (int) config('media-studio.trash.days', 30)));
    }

    public function workspace(Principal $principal, string $workspaceId, bool $lock = false): object
    {
        $identity = DB::table('fms_workspaces')->where('public_id', $workspaceId)->first(['tenant_type', 'tenant_id', 'tenant_key']);
        abort_if($identity === null, 404);
        $workspace = $this->access->resolveForWorker($principal, $workspaceId, TenantIdentity::fromWorkspace($identity), write: true, lock: $lock, operation: 'files.trash');
        abort_unless($workspace->role === 'owner', 403);

        return $workspace;
    }

    /** Admit one entry. No remote filesystem operations occur under the SQL lock. */
    public function schedule(Principal $principal, string $workspaceId, string $entryId): string
    {
        abort_unless(config('media-studio.trash.retention_enabled', false), 409, 'Automatic trash retention is disabled.');

        return DB::transaction(function () use ($principal, $workspaceId, $entryId): string {
            $workspace = $this->workspace($principal, $workspaceId, lock: true);
            $entry = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('public_id', $entryId)->lockForUpdate()->first();
            abort_if($entry === null, 404);
            abort_unless($entry->source_kind === 'managed' && $entry->purging_at === null && $entry->trashed_at !== null
                && $entry->trashed_at <= $this->cutoff()->toDateTimeString(), 409, 'This item is not eligible for retention.');
            $this->assertUnreferenced($entry);
            app(TrashShares::class)->remove((int) $workspace->id, DB::table('fms_entries')->where('id', $entry->id)->select('id'));
            if ($entry->trash_group_id !== null) {
                DB::table('fms_tree_operations')->where('workspace_id', $workspace->id)->where('id', $entry->trash_group_id)->whereNull('purging_at')->update(['purging_at' => now()]);
            }
            $objects = $this->objects($workspace, $entry);
            $id = (string) Str::ulid();
            $tenant = TenantIdentity::fromWorkspace($workspace);
            DB::table('fms_trash_purges')->insert([
                'id' => $id, 'workspace_id' => $workspace->id, 'workspace_public_id' => $workspace->public_id, 'entry_id' => $entry->id, 'entry_public_id' => $entry->public_id,
                'version_id' => $entry->current_version_id, 'revision' => $entry->revision + 1, 'state' => 'queued',
                'principal_type' => $principal->type, 'principal_id' => $principal->id,
                'tenant_type' => $tenant?->type, 'tenant_id' => $tenant?->id, 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_chunk($objects, 100) as $batch) {
                DB::table('fms_trash_purge_objects')->insert(array_map(static fn (array $object): array => [
                    'purge_id' => $id, 'disk' => $object['disk'], 'object_key' => $object['key'],
                    'object_hash' => hash('sha256', json_encode($object, JSON_THROW_ON_ERROR)), 'deleted_at' => null,
                ], $batch));
            }
            DB::table('fms_entries')->where('id', $entry->id)->update(['purging_at' => now(), 'revision' => $entry->revision + 1, 'updated_at' => now()]);

            return $id;
        }, 3);
    }

    public function dispatch(string $id): bool
    {
        $claimed = DB::table('fms_trash_purges')->where('id', $id)->whereIn('state', ['queued', 'failed', 'processing'])
            ->where('attempts', '<', $this->maxAttempts())
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subMinutes(5)))
            ->update(['last_dispatched_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return false;
        }
        $job = new PurgeTrashEntry($id);
        $job->onConnection(config('media-studio.trash.connection'))->onQueue(config('media-studio.trash.queue'));
        app(Dispatcher::class)->dispatch($job);

        return true;
    }

    public function run(string $id): void
    {
        $token = bin2hex(random_bytes(16));
        $operation = DB::transaction(function () use ($id, $token): ?object {
            $operation = DB::table('fms_trash_purges')->where('id', $id)->lockForUpdate()->first();
            if ($operation === null || $operation->state === 'completed' || $operation->attempts >= $this->maxAttempts()
                || ($operation->lease_expires_at !== null && $operation->lease_expires_at > now()->toDateTimeString())) {
                return null;
            }
            DB::table('fms_trash_purges')->where('id', $id)->update(['state' => 'processing', 'lease_token' => $token,
                'lease_expires_at' => now()->addMinutes(5), 'attempts' => $operation->attempts + 1, 'failure_code' => null, 'updated_at' => now()]);

            return $operation;
        });
        if ($operation === null) {
            return;
        }
        try {
            $objects = DB::table('fms_trash_purge_objects')->where('purge_id', $id)->whereNull('deleted_at')->orderBy('id')->get();
            foreach ($objects as $object) {
                DB::transaction(fn () => $this->recheck($operation, $token));
                $disk = Storage::disk($object->disk);
                if (! $disk->delete($object->object_key) && $disk->exists($object->object_key)) {
                    throw new RuntimeException('The storage provider did not delete a managed object.');
                }
                DB::table('fms_trash_purge_objects')->where('id', $object->id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
            }
            DB::transaction(function () use ($operation, $token): void {
                [$workspace, $entry, $principal] = $this->recheck($operation, $token);
                abort_if(DB::table('fms_trash_purge_objects')->where('purge_id', $operation->id)->whereNull('deleted_at')->exists(), 409);
                app(ActivityLog::class)->record($workspace, $principal, $entry, 'purged', $operation->id);
                DB::table('fms_entries')->where('id', $entry->id)->delete();
                DB::table('fms_trash_purge_objects')->where('purge_id', $operation->id)->delete();
                DB::table('fms_trash_purges')->where('id', $operation->id)->where('lease_token', $token)->update([
                    'state' => 'completed', 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
                ]);
            }, 3);
        } catch (Throwable $error) {
            DB::table('fms_trash_purges')->where('id', $id)->where('lease_token', $token)->update([
                'state' => 'failed', 'failure_code' => 'purge_incomplete', 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
            ]);
            throw $error;
        }
    }

    /** Reauthorize a stopped operation under a current owner, preserving per-object progress. */
    public function resume(Principal $principal, string $workspaceId, string $id): void
    {
        abort_unless(config('media-studio.trash.retention_enabled', false), 409, 'Trash retention is disabled.');
        DB::transaction(function () use ($principal, $workspaceId, $id): void {
            $workspace = $this->workspace($principal, $workspaceId, lock: true);
            $operation = DB::table('fms_trash_purges')->where('workspace_id', $workspace->id)->where('id', $id)->lockForUpdate()->first();
            abort_if($operation === null, 404);
            abort_if($operation->state === 'completed' || ($operation->lease_expires_at !== null && $operation->lease_expires_at > now()->toDateTimeString()), 409);
            $tenant = TenantIdentity::fromWorkspace($workspace);
            DB::table('fms_trash_purges')->where('id', $id)->update([
                'principal_type' => $principal->type, 'principal_id' => $principal->id,
                'tenant_type' => $tenant?->type, 'tenant_id' => $tenant?->id,
                'state' => 'queued', 'attempts' => 0, 'lease_token' => null, 'lease_expires_at' => null,
                'last_dispatched_at' => null, 'failure_code' => null, 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** Recheck scope under the workspace lock before deleting bytes or committing metadata. */
    private function recheck(object $operation, string $token): array
    {
        abort_unless(config('media-studio.trash.retention_enabled', false), 409);
        $principal = new Principal($operation->principal_type, $operation->principal_id);
        $tenant = $operation->tenant_type === null ? null : new TenantIdentity($operation->tenant_type, $operation->tenant_id);
        $workspace = $this->access->resolveForWorker($principal, $operation->workspace_public_id, $tenant, write: true, lock: true, operation: 'files.trash');
        abort_unless($workspace->role === 'owner' && (int) $workspace->id === (int) $operation->workspace_id, 403);
        $entry = DB::table('fms_entries')->where('id', $operation->entry_id)->where('workspace_id', $workspace->id)->lockForUpdate()->first();
        abort_unless($entry !== null && $entry->purging_at !== null && $entry->source_kind === 'managed'
            && (int) $entry->revision === (int) $operation->revision && $entry->current_version_id === $operation->version_id
            && $entry->trashed_at !== null && $entry->trashed_at <= $this->cutoff()->toDateTimeString(), 409);
        $this->assertUnreferenced($entry);
        abort_unless(DB::table('fms_trash_purges')->where('id', $operation->id)->where('state', 'processing')->where('lease_token', $token)
            ->where('lease_expires_at', '>', now())->exists(), 409, 'The purge lease expired.');

        return [$workspace, $entry, $principal];
    }

    private function assertUnreferenced(object $entry): void
    {
        abort_if(DB::table('fms_usages')->where('entry_id', $entry->id)->exists(), 409, 'This item is still referenced.');
        abort_if(DB::table('fms_entries')->where('parent_id', $entry->id)->exists(), 409, 'Remove or restore child items first.');
        abort_if(DB::table('fms_renditions')->where('entry_id', $entry->id)->where('state', 'processing')->where('lease_expires_at', '>', now())->exists(), 409, 'Wait for active processing before pruning.');
    }

    /** @return list<array{disk:string,key:string}> */
    private function objects(object $workspace, object $entry): array
    {
        $max = min(1000, max(1, (int) config('media-studio.trash.max_objects_per_entry', 1000)));
        $versions = DB::table('fms_asset_versions')->where('entry_id', $entry->id)->limit($max + 1)->get();
        abort_if($versions->count() > $max, 409, 'This item exceeds the configured cleanup budget.');
        $prefix = trim($workspace->storage_root, '/').'/'.$workspace->public_id;
        $objects = [];
        $add = static function (string $disk, string $key) use (&$objects, $max): void {
            $objects[hash('sha256', $disk."\0".$key)] = ['disk' => $disk, 'key' => $key];
            abort_if(count($objects) > $max, 409, 'This item exceeds the configured cleanup budget.');
        };
        foreach ($versions as $version) {
            abort_unless($version->object_key === $prefix.'/originals/'.$version->id && Str::isUlid($version->id)
                && is_string($version->sha256) && preg_match('/\A[a-f0-9]{64}\z/', $version->sha256), 409, 'Only Studio-owned original objects can be pruned.');
            $add($version->disk, $version->object_key);
        }
        $versionIds = $versions->pluck('id')->all();
        $renditions = DB::table('fms_renditions')->where('entry_id', $entry->id)->where('owned', true)->whereNotNull('object_key')->limit($max + 1)->get();
        abort_if($renditions->count() > $max, 409);
        foreach ($renditions as $rendition) {
            abort_unless(in_array($rendition->version_id, $versionIds, true) && $this->nativeKey($prefix, $rendition->object_key, $versionIds), 409, 'Only Studio-owned conversion objects can be pruned.');
            $add($rendition->disk, $rendition->object_key);
        }

        return array_values($objects);
    }

    private function nativeKey(string $prefix, string $key, array $versions): bool
    {
        $base = $prefix.'/renditions/';

        return str_starts_with($key, $base)
            && preg_match('/\A([0-9A-HJKMNP-TV-Z]{26})\/[a-f0-9]{32}\/[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/', substr($key, strlen($base)), $match)
            && in_array($match[1], $versions, true);
    }
}
