<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Workspaces;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Assistant\AssistantAttachments;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Navigation\NavigationChanges;
use UseFlex\MediaStudio\Uploads\UploadAttempts;
use UseFlex\MediaStudio\Uploads\UploadSessions;

final class WorkspaceRetirement
{
    private ?array $entryTables = null;

    public function __construct(private WorkspaceAccess $access) {}

    public function start(Authenticatable $actor, string $source, string $confirmation, string $mode, ?string $target): array
    {
        abort_unless(config('media-studio.workspaces.enabled', true), 403);
        $principal = Principal::fromUser($actor);
        $id = DB::transaction(function () use ($principal, $source, $confirmation, $mode, $target) {
            $w = $this->access->resolve($principal, $source, true, true);
            abort_unless($w->role === 'owner', 403);
            abort_unless(hash_equals($w->name, $confirmation), 422, 'Confirm the exact workspace name.');
            if ($w->retirement_id) {
                return $w->retirement_id;
            }
            abort_if(DB::table('fms_workspace_defaults')->where('workspace_id', $w->id)->exists(), 409, 'Select another default workspace first.');
            abort_unless(in_array($mode, ['move', 'delete'], true), 422);
            if ($mode === 'move') {
                $t = $this->access->resolve($principal, (string) $target, true, true);
                abort_unless($t->role === 'owner' && $t->id !== $w->id && ! $t->retirement_id, 422, 'Choose another owned active workspace.');
            }
            abort_if(DB::table('fms_entries')->where('workspace_id', $w->id)->whereNotNull('purging_at')->exists(), 409, 'Wait for pending trash cleanup before removing the workspace.');
            abort_if(DB::table('fms_upload_attempts')->where('workspace_public_id', $w->public_id)->whereNull('writer_finished_at')->where('lease_expires_at', '>', now())->exists(), 409, 'Wait for active upload writes to finish.');
            abort_if(DB::table('fms_renditions')->whereIn('entry_id', DB::table('fms_entries')->select('id')->where('workspace_id', $w->id))->where('state', 'processing')->where('lease_expires_at', '>', now())->exists(), 409, 'Wait for active file processing to finish.');
            abort_if(DB::table('fms_upload_sessions')->where('workspace_id', $w->id)->where('state', 'finalizing')->where('lease_expires_at', '>', now())->exists(), 409, 'Wait for active uploads to finish.');
            if (Schema::hasTable('fms_ai_runs')) {
                abort_if(DB::table('fms_ai_runs')->whereIn('conversation_id', DB::table('fms_ai_conversations')->select('id')->where('workspace_id', $w->id))->whereIn('state', ['queued', 'running'])->exists(), 409, 'Stop active assistant responses first.');
            }
            abort_if(DB::table('fms_archive_jobs')->where('workspace_id', $w->id)->whereIn('state', ['queued', 'processing'])->exists(), 409, 'Wait for active archives to finish.');
            $id = (string) Str::ulid();
            $tenant = TenantIdentity::fromWorkspace($w);
            DB::table('fms_workspace_operations')->insert(['id' => $id, 'workspace' => $source, 'source_id' => $w->id, 'name' => $w->name, 'mode' => $mode, 'target' => $mode === 'move' ? $target : null, 'principal_type' => $principal->type, 'principal_id' => $principal->id, 'principal_key' => $principal->key(), 'tenant_type' => $tenant?->type, 'tenant_id' => $tenant?->id, 'state' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fms_workspaces')->where('id', $w->id)->update(['retirement_id' => $id]);
            DB::table('fms_shares')->where('workspace_id', $w->id)->update(['revoked_at' => now(), 'revision' => DB::raw('revision + 1')]);
            DB::table('fms_upload_sessions')->where('workspace_id', $w->id)->whereIn('state', ['uploading', 'initializing'])->update(['state' => 'cancelled', 'expires_at' => now()]);

            return $id;
        }, 3);

        return $this->card(DB::table('fms_workspace_operations')->where('id', $id)->first());
    }

    public function dispatch(string $id): void
    {
        if (DB::table('fms_workspace_operations')->where('id', $id)->whereIn('state', ['queued', 'working'])->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(5)))->update(['dispatched_at' => now()]) !== 1) {
            return;
        }
        $job = new RetireWorkspaceJob($id);
        $job->onConnection(config('media-studio.workspaces.operations.connection'))->onQueue(config('media-studio.workspaces.operations.queue', 'media'));
        app(Dispatcher::class)->dispatch($job);
    }

    public function card(object $o): array
    {
        return ['id' => $o->id, 'workspace' => $o->workspace, 'mode' => $o->mode, 'target' => $o->target, 'state' => $o->state, 'phase' => $o->phase, 'processed' => (int) $o->processed, 'error' => $o->error];
    }

    private function authorize(object $o): array
    {
        abort_unless(DB::table('fms_workspace_operations')->where('id', $o->id)->where('lease', $o->lease)->where('lease_until', '>', now())->exists(), 409, 'Workspace operation lease expired.');
        $p = new Principal($o->principal_type, $o->principal_id);
        $tenant = $o->tenant_type ? new TenantIdentity($o->tenant_type, $o->tenant_id) : null;
        // The source is intentionally frozen. Recheck the owner without bypassing tenant/permission policy.
        $w = $this->access->resolveForWorker($p, $o->workspace, $tenant, false, true, 'workspaces.manage');
        abort_unless($w->role === 'owner' && $w->retirement_id === $o->id, 403);
        $target = $o->target ? $this->access->resolveForWorker($p, $o->target, $tenant, true, true, 'workspaces.manage') : null;
        if ($target) {
            abort_unless($target->role === 'owner', 403);
        }

        return [$w, $target];
    }

    public function step(string $id): void
    {
        $token = bin2hex(random_bytes(16));
        $o = DB::transaction(function () use ($id, $token) {
            $o = DB::table('fms_workspace_operations')->where('id', $id)->lockForUpdate()->first();
            if (! $o || in_array($o->state, ['completed', 'failed'], true) || $o->lease_until && $o->lease_until > now()->toDateTimeString()) {
                return null;
            }DB::table('fms_workspace_operations')->where('id', $id)->update(['state' => 'working', 'dispatched_at' => null, 'lease' => $token, 'lease_until' => now()->addMinutes(5), 'updated_at' => now()]);
            $o->lease = $token;

            return $o;
        });
        if (! $o) {
            return;
        }
        try {
            [$w,$target] = DB::transaction(fn () => $this->authorize($o));
            if ($o->phase === 'entries') {
                if ($target && ! $o->folder_id) {
                    $o->folder_id = DB::transaction(function () use ($o, $target) {
                        $this->authorize($o);
                        $name = mb_substr($o->name, 0, 140).' ('.substr($o->id, -8).')';
                        $folder = DB::table('fms_entries')->insertGetId(['workspace_id' => $target->id, 'public_id' => (string) Str::ulid(), 'parent_id' => null, 'parent_scope' => 0, 'kind' => 'folder', 'sort_group' => 0, 'name' => $name, 'normalized_name' => mb_strtolower($name), 'created_at' => now(), 'updated_at' => now()]);
                        app(NavigationChanges::class)->record((int) $target->id, 'workspace-transfer', []);
                        DB::table('fms_workspace_operations')->where('id', $o->id)->update(['folder_id' => $folder]);

                        return $folder;
                    });
                }
                for ($i = 0; $i < 5; $i++) {
                    $entries = DB::table('fms_entries as e')->where('e.workspace_id', $w->id);
                    if ($target) {
                        $entries->where(fn ($q) => $q->whereNull('e.parent_id')->orWhereIn('e.parent_id', DB::table('fms_entries')->select('id')->where('workspace_id', $target->id)))->orderBy('e.id');
                    } else {
                        $entries->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fms_entries as c')->whereColumn('c.parent_id', 'e.id'))->orderByDesc('e.id');
                    }
                    $entry = $entries->first();
                    if (! $entry) {
                        abort_if(DB::table('fms_entries')->where('workspace_id', $w->id)->exists(), 409, 'The folder hierarchy cannot be traversed.');
                        $this->phase($o, 'extras');
                        break;
                    }
                    $this->entry($o, $w, $target, $entry);
                }
            } elseif ($o->phase === 'extras') {
                $session = DB::table('fms_upload_sessions')->where('workspace_id', $w->id)->whereNull('cleaned_at')->orderBy('id')->first();
                if ($session) {
                    abort_unless(app(UploadSessions::class)->cleanup($session), 409, 'Upload cleanup is not complete.');
                } elseif (Schema::hasTable('fms_ai_attachments') && ($attachment = DB::table('fms_ai_attachments')->where('workspace_id', $w->id)->first())) {
                    $path = app(AssistantAttachments::class)->path($attachment->id);
                    if (is_file($path) && ! unlink($path)) {
                        throw new RuntimeException('Attachment cleanup failed.');
                    }DB::table('fms_ai_attachments')->where('id', $attachment->id)->delete();
                } elseif ($archive = DB::table('fms_archive_jobs')->where('workspace_id', $w->id)->first()) {
                    if ($archive->object_key) {
                        abort_unless(preg_match('/\Aflex-media-studio\/archives\/'.preg_quote($archive->id, '/').'[\-][a-f0-9]{32}\.zip\z/', $archive->object_key), 409, 'Archive storage ownership could not be verified.');
                        $this->object($o, null, 'archive', $archive->id, $archive->disk, $archive->object_key, null, null);
                    }
                    DB::table('fms_archive_jobs')->where('id', $archive->id)->delete();
                } elseif (DB::table('fms_retired_renditions')->where('workspace_id', $w->id)->exists()) {
                    foreach (DB::table('fms_retired_renditions')->where('workspace_id', $w->id)->orderBy('id')->limit(20)->get() as $retired) {
                        abort_unless(str_starts_with($retired->object_key, trim($w->storage_root, '/').'/'.$w->public_id.'/renditions/'), 409, 'Retired object ownership could not be verified.');
                        $this->object($o, null, 'retired', (string) $retired->id, $retired->disk, $retired->object_key, null, null);
                        DB::table('fms_retired_renditions')->where('id', $retired->id)->delete();
                    }
                } elseif (DB::table('fms_upload_attempts')->where('workspace_public_id', $w->public_id)->whereNotNull('writer_finished_at')->exists()) {
                    $attempts = DB::table('fms_upload_attempts')->where('workspace_public_id', $w->public_id)->whereNotNull('writer_finished_at')->orderBy('id')->limit(100)->get();
                    foreach ($attempts as $attempt) {
                        if (! in_array($attempt->state, ['accepted', 'retained'], true) && ! $attempt->cleaned_at) {
                            DB::table('fms_upload_attempts')->where('id', $attempt->id)->update(['next_cleanup_at' => now()]);
                            app(UploadAttempts::class)->cleanup($attempt->id);
                        }
                    }
                    DB::table('fms_upload_attempts')->whereIn('id', $attempts->pluck('id'))->delete();
                } else {
                    $this->phase($o, 'objects');
                }
            } elseif ($o->phase === 'objects') {
                $objects = DB::table('fms_workspace_objects')->where('operation', $id)->whereNull('deleted_at')->orderBy('id')->limit(20)->get();
                foreach ($objects as $object) {
                    DB::transaction(fn () => $this->authorize($o));
                    $referenced = DB::table('fms_entries')->where(fn ($q) => $q->where(fn ($q) => $q->where('thumbnail_disk', $object->disk)->where('thumbnail_key', $object->object_key))->orWhere(fn ($q) => $q->where('preview_disk', $object->disk)->where('preview_key', $object->object_key)))->exists() || DB::table('fms_asset_versions')->where('disk', $object->disk)->where('object_key', $object->object_key)->exists() || DB::table('fms_renditions')->where('disk', $object->disk)->where('object_key', $object->object_key)->exists();
                    if (! $referenced) {
                        $disk = Storage::disk($object->disk);
                        if (! $disk->delete($object->object_key) && $disk->exists($object->object_key)) {
                            throw new RuntimeException('Storage cleanup failed.');
                        }
                    }
                    DB::table('fms_workspace_objects')->where('id', $object->id)->update(['deleted_at' => now()]);
                }if ($objects->isEmpty()) {
                    $this->phase($o, 'finalize');
                }
            } elseif ($o->phase === 'finalize') {
                DB::transaction(function () use ($o) {
                    [$w] = $this->authorize($o);
                    abort_if(DB::table('fms_entries')->where('workspace_id', $w->id)->exists(), 409);
                    DB::table('fms_usages')->where('workspace_id', $w->id)->delete();
                    DB::table('fms_workspaces')->where('id', $w->id)->delete();
                    DB::table('fms_workspace_operations')->where('id', $o->id)->update(['state' => 'completed', 'error' => null]);
                });
            }
            DB::table('fms_workspace_operations')->where('id', $id)->where('lease', $token)->update(['lease' => null, 'lease_until' => null, 'updated_at' => now()]);
            $connection = config('media-studio.workspaces.operations.connection') ?? config('queue.default');
            if (config('queue.connections.'.$connection.'.driver') !== 'sync') {
                $this->dispatch($id);
            }
        } catch (Throwable $e) {
            DB::table('fms_workspace_operations')->where('id', $id)->where('lease', $token)->update(['state' => 'failed', 'error' => 'Operation stopped. Review permissions, active jobs or storage, then retry.', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
            report($e);
        }
    }

    private function phase(object $o, string $phase): void
    {
        DB::table('fms_workspace_operations')->where('id', $o->id)->update(['phase' => $phase]);
    }

    private function object(object $o, ?int $entry, string $table, string $row, string $disk, string $key, ?string $targetDisk, ?string $targetKey): object
    {
        $identity = hash('sha256', json_encode([$o->id, $table, $row, $disk, $key], JSON_THROW_ON_ERROR));
        DB::table('fms_workspace_objects')->insertOrIgnore(['operation' => $o->id, 'entry_id' => $entry, 'source_table' => $table, 'source_id' => $row, 'disk' => $disk, 'object_key' => $key, 'target_disk' => $targetDisk, 'target_key' => $targetKey, 'identity' => $identity]);

        return DB::table('fms_workspace_objects')->where('identity', $identity)->first();
    }

    private function entry(object $o, object $w, ?object $target, object $e): void
    {
        $prefix = trim($w->storage_root, '/').'/'.$w->public_id.'/';
        $targetPrefix = $target ? trim($target->storage_root, '/').'/'.$target->public_id.'/' : null;
        foreach (['fms_asset_versions', 'fms_renditions'] as $table) {
            $rows = DB::table($table)->where('entry_id', $e->id)->limit(1001)->get();
            abort_if($rows->count() > 1000, 409, 'Per-entry object budget exceeded.');
            foreach ($rows as $r) {
                if (! $r->object_key || $table === 'fms_renditions' && ! $r->owned) {
                    continue;
                }
                if ($table === 'fms_asset_versions' && $e->source_kind !== 'managed') {
                    continue;
                }
                abort_unless(str_starts_with($r->object_key, $prefix) && ! str_contains($r->object_key, '..'), 409, 'Object namespace ownership could not be verified.');
                $this->object($o, $e->id, $table, (string) $r->id, $r->disk, $r->object_key, $target ? $target->disk : null, $target ? $targetPrefix.substr($r->object_key, strlen($prefix)) : null);
            }
        }
        $objects = DB::table('fms_workspace_objects')->where('operation', $o->id)->where('entry_id', $e->id)->get();
        if ($target) {
            foreach ($objects as $object) {
                if ($object->copied_at) {
                    continue;
                }
                $source = Storage::disk($object->disk);
                $dest = Storage::disk($object->target_disk);
                $stream = $source->readStream($object->object_key);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Cannot read original object.');
                }$hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                fclose($stream);
                $expected = hash_final($hash);
                if ($object->source_table === 'fms_asset_versions') {
                    $known = DB::table('fms_asset_versions')->where('id', $object->source_id)->value('sha256');
                    abort_unless(is_string($known) && hash_equals($known, $expected), 409, 'The source object no longer matches its stored checksum.');
                }
                $stream = $source->readStream($object->object_key);
                try {
                    if (! is_resource($stream) || ! $dest->put($object->target_key, $stream, ['visibility' => 'private'])) {
                        throw new RuntimeException('Cannot copy object.');
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                $stream = $dest->readStream($object->target_key);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Cannot verify copied object.');
                }$hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                fclose($stream);
                if (! hash_equals($expected, hash_final($hash))) {
                    throw new RuntimeException('Copied object checksum differs.');
                }DB::table('fms_workspace_objects')->where('id', $object->id)->update(['copied_at' => now()]);
            }
        }
        DB::transaction(function () use ($o, $e, $target, $objects) {
            $this->authorize($o);
            $row = DB::table('fms_entries')->where('id', $e->id)->lockForUpdate()->first();
            abort_unless($row && (int) $row->workspace_id === (int) $o->source_id && (int) $row->revision === (int) $e->revision, 409);
            if ($target) {
                $values = ['workspace_id' => $target->id, 'parent_id' => $row->parent_id ?? $o->folder_id, 'parent_scope' => $row->parent_id ?? $o->folder_id, 'revision' => $row->revision + 1, 'updated_at' => now()];
                foreach ($objects as $object) {
                    DB::table($object->source_table)->where('id', $object->source_id)->update(['disk' => $object->target_disk, 'object_key' => $object->target_key]);
                    foreach (['thumbnail', 'preview'] as $variant) {
                        if ($row->{$variant.'_key'} === $object->object_key && $row->{$variant.'_disk'} === $object->disk) {
                            $values[$variant.'_key'] = $object->target_key;
                            $values[$variant.'_disk'] = $object->target_disk;
                        }
                    }
                }
                // Move tag definitions per entry with deterministic deduplication.
                foreach (DB::table('fms_entry_tags as et')->join('fms_tags as t', 't.id', '=', 'et.tag_id')->where('et.entry_id', $e->id)->limit(1000)->get(['t.*']) as $tag) {
                    $tagId = DB::table('fms_tags')->where('workspace_id', $target->id)->where('locale', $tag->locale)->where('normalized_name', $tag->normalized_name)->value('id');
                    if (! $tagId) {
                        $attrs = (array) $tag;
                        unset($attrs['id']);
                        $attrs['public_id'] = (string) Str::ulid();
                        $attrs['workspace_id'] = $target->id;
                        $tagId = DB::table('fms_tags')->insertGetId($attrs);
                    }DB::table('fms_entry_tags')->where('entry_id', $e->id)->where('tag_id', $tag->id)->delete();
                    DB::table('fms_entry_tags')->insertOrIgnore(['entry_id' => $e->id, 'tag_id' => $tagId]);
                }
                foreach ($this->entryTables ??= array_values(array_filter(['fms_usages', 'fms_comments', 'fms_personal_entries', 'fms_spatie_sources', 'fms_disk_sources', 'fms_search_content', 'fms_content_jobs', 'fms_ai_labels'], fn ($table) => Schema::hasTable($table) && Schema::hasColumn($table, 'entry_id') && Schema::hasColumn($table, 'workspace_id'))) as $table) {
                    DB::table($table)->where('entry_id', $e->id)->update(['workspace_id' => $target->id]);
                }
                DB::table('fms_entries')->where('id', $e->id)->update($values);
                app(NavigationChanges::class)->record((int) $target->id, 'workspace-transfer', []);
            } else {
                DB::table('fms_usages')->where('entry_id', $e->id)->delete();
                DB::table('fms_entries')->where('id', $e->id)->delete();
            }
            app(NavigationChanges::class)->record((int) $o->source_id, 'workspace-transfer', []);
            DB::table('fms_workspace_operations')->where('id', $o->id)->increment('processed');
        }, 3);
    }
}
