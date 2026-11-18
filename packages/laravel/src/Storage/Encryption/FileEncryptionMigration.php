<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use UseFlex\MediaCore\Encryption\EncryptionFailure;

/** Copy, authenticate, verify digest, then atomically publish catalogue pointers. */
final class FileEncryptionMigration
{
    private const PHASES = [
        ['fms_asset_versions', 'v', 'disk', 'object_key'],
        ['fms_renditions', 'v', 'disk', 'object_key'],
        ['fms_entries', 'e', 'thumbnail_disk', 'thumbnail_key'],
        ['fms_entries', 'e', 'preview_disk', 'preview_key'],
        ['fms_archive_jobs', 'v', 'disk', 'object_key'],
    ];

    public function start(string $workspace, string $target): string
    {
        $this->target($target);

        return DB::transaction(function () use ($workspace, $target): string {
            $w = DB::table('fms_workspaces')->where('public_id', $workspace)->lockForUpdate()->firstOrFail();
            if ($w->encryption_operation !== null || $w->retirement_id !== null) {
                throw new EncryptionFailure('Another workspace operation is active.');
            }
            if (DB::table('fms_upload_attempts')->where('workspace_id', $w->id)->where('state', 'writing')->exists()
                || DB::table('fms_upload_sessions')->where('workspace_id', $w->id)->whereNotIn('state', ['completed', 'cancelled', 'expired', 'failed'])->exists()
                || DB::table('fms_renditions as r')->join('fms_entries as e', 'e.id', '=', 'r.entry_id')->where('e.workspace_id', $w->id)->whereIn('r.state', ['queued', 'processing'])->exists()
                || DB::table('fms_archive_jobs')->where('workspace_id', $w->id)->whereIn('state', ['queued', 'processing'])->exists()) {
                throw new EncryptionFailure('Drain uploads and processing jobs before starting file migration.');
            }
            $id = (string) Str::ulid();
            DB::table('fms_encryption_operations')->insert(['id' => $id, 'kind' => 'files', 'state' => 'pending',
                'options' => json_encode(['workspace' => $w->id, 'public_id' => $workspace, 'root' => $w->storage_root, 'target' => $target], JSON_THROW_ON_ERROR),
                'checkpoint' => json_encode(['phase' => 0, 'after' => null], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fms_workspaces')->where('id', $w->id)->update(['encryption_operation' => $id]);

            return $id;
        });
    }

    public function step(string $id, int $limit = 1): object
    {
        $lease = bin2hex(random_bytes(16));
        $claimed = DB::table('fms_encryption_operations')->where('id', $id)->where('kind', 'files')->where('state', '!=', 'completed')
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))->update(['lease' => $lease, 'lease_until' => now()->addMinutes(2), 'state' => 'running', 'error' => null, 'updated_at' => now()]);
        if (! $claimed) {
            return DB::table('fms_encryption_operations')->where('id', $id)->where('kind', 'files')->firstOrFail();
        }
        try {
            for ($i = 0; $i < min(25, max(1, $limit)); $i++) {
                $op = DB::table('fms_encryption_operations')->where('id', $id)->firstOrFail();
                $options = json_decode($op->options, true, flags: JSON_THROW_ON_ERROR);
                $this->target($options['target']);
                $cursor = json_decode($op->checkpoint, true, flags: JSON_THROW_ON_ERROR);
                $row = null;
                while (isset(self::PHASES[$cursor['phase']])) {
                    [$table, $alias, $diskColumn, $keyColumn] = self::PHASES[$cursor['phase']];
                    $query = DB::table($table.' as '.$alias);
                    if ($alias === 'v' && $table !== 'fms_archive_jobs') {
                        $query->join('fms_entries as e', 'e.id', '=', 'v.entry_id')->where('e.workspace_id', $options['workspace'])->where('e.source_kind', 'managed');
                    } else {
                        $query->where($alias.'.workspace_id', $options['workspace']);
                        if ($alias === 'e') {
                            $query->where('e.source_kind', 'managed');
                        }
                    }
                    if ($table === 'fms_renditions') {
                        $query->where('v.owned', true)->where('v.state', 'ready');
                    }
                    if ($cursor['after'] !== null) {
                        $query->where($alias.'.id', '>', $cursor['after']);
                    }
                    $row = $query->whereNotNull($alias.'.'.$diskColumn)->whereNotNull($alias.'.'.$keyColumn)->orderBy($alias.'.id')->first([$alias.'.*']);
                    if ($row) {
                        break;
                    }
                    $cursor = ['phase' => $cursor['phase'] + 1, 'after' => null];
                }
                if ($row === null) {
                    DB::transaction(function () use ($id, $lease, $options, $cursor): void {
                        $this->assertLease($id, $lease);
                        $w = DB::table('fms_workspaces')->where('id', $options['workspace'])->lockForUpdate()->firstOrFail();
                        if ($w->encryption_operation !== $id) {
                            throw new EncryptionFailure('Workspace migration lock changed.');
                        }
                        DB::table('fms_workspaces')->where('id', $w->id)->update(['disk' => $options['target'], 'encryption_operation' => null]);
                        DB::table('fms_encryption_operations')->where('id', $id)->update(['state' => 'completed', 'checkpoint' => json_encode($cursor), 'updated_at' => now()]);
                    });
                    break;
                }
                $sourceDisk = $row->{$diskColumn};
                $sourceKey = $row->{$keyColumn};
                $copy = $this->copy($id, $lease, $options, $sourceDisk, $sourceKey, $table === 'fms_asset_versions' ? $row->sha256 : null, $table === 'fms_asset_versions' ? (int) $row->size_bytes : null);
                DB::transaction(function () use ($id, $lease, $options, $cursor, $row, $table, $diskColumn, $keyColumn, $sourceDisk, $sourceKey, $copy): void {
                    $this->assertLease($id, $lease);
                    $current = DB::table($table)->where('id', $row->id)->lockForUpdate()->firstOrFail();
                    if ($table === 'fms_asset_versions' && ($current->sha256 !== $row->sha256 || (int) $current->size_bytes !== (int) $row->size_bytes)) {
                        throw new EncryptionFailure('The original digest changed before publication.');
                    }
                    if ($current->{$diskColumn} !== $sourceDisk || $current->{$keyColumn} !== $sourceKey) {
                        throw new EncryptionFailure('Migration source pointer changed.');
                    }
                    DB::table($table)->where('id', $row->id)->update([$diskColumn => $options['target'], $keyColumn => $copy->target_key]);
                    DB::table('fms_encryption_copies')->where('id', $copy->id)->update(['state' => 'published', 'updated_at' => now()]);
                    $cursor['after'] = $row->id;
                    DB::table('fms_encryption_operations')->where('id', $id)->update(['checkpoint' => json_encode($cursor), 'processed' => DB::raw('processed + 1'), 'updated_at' => now()]);
                });
            }
            DB::table('fms_encryption_operations')->where('id', $id)->where('lease', $lease)->where('state', 'running')->update(['state' => 'pending', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('fms_encryption_operations')->where('id', $id)->where('lease', $lease)->update(['state' => 'failed', 'error' => 'Copy or verification failed. Original pointers and recovery copies were retained.', 'updated_at' => now()]);
            throw new EncryptionFailure('File migration stopped safely. Resolve the failure and resume the same operation.');
        } finally {
            DB::table('fms_encryption_operations')->where('id', $id)->where('lease', $lease)->update(['lease' => null, 'lease_until' => null]);
        }

        return DB::table('fms_encryption_operations')->where('id', $id)->firstOrFail();
    }

    private function copy(string $id, string $lease, array $options, string $disk, string $key, ?string $expectedHash, ?int $expectedSize): object
    {
        $sourceHash = hash('sha256', $disk."\0".$key);
        $targetKey = trim($options['root'], '/').'/'.$options['public_id'].'/encryption/'.$id.'/'.$sourceHash.'-'.$lease;
        DB::table('fms_encryption_copies')->insertOrIgnore(['operation_id' => $id, 'workspace_id' => $options['workspace'], 'source_hash' => $sourceHash, 'source_disk' => $disk, 'source_key' => $key, 'target_disk' => $options['target'], 'target_key' => $targetKey, 'created_at' => now(), 'updated_at' => now()]);
        $copy = DB::table('fms_encryption_copies')->where('operation_id', $id)->where('source_hash', $sourceHash)->firstOrFail();
        if ($copy->state === 'allocated') {
            DB::transaction(function () use ($id, $lease, $copy, $targetKey): void {
                $this->assertLease($id, $lease);
                DB::table('fms_encryption_copies')->where('id', $copy->id)->where('state', 'allocated')->update(['target_key' => $targetKey, 'updated_at' => now()]);
            });
            $copy = DB::table('fms_encryption_copies')->where('id', $copy->id)->firstOrFail();
        }
        $targetKey = $copy->target_key;
        $source = Storage::disk($disk);
        $target = Storage::disk($options['target']);
        $lastBeat = 0.;
        $heartbeat = function () use ($id, $lease, &$lastBeat): void {
            if (microtime(true) - $lastBeat < 10) {
                return;
            }
            if (! DB::table('fms_encryption_operations')->where('id', $id)->where('lease', $lease)->where('lease_until', '>', now())->update(['lease_until' => now()->addMinutes(2), 'updated_at' => now()])) {
                throw new EncryptionFailure('Migration lease was lost.');
            }
            $lastBeat = microtime(true);
        };
        if (! in_array($copy->state, ['verified', 'published'], true)) {
            $input = $source->readStream($key);
            if (! is_resource($input)) {
                throw new EncryptionFailure('Migration source is unavailable.');
            }
            $size = $expectedSize ?? $source->size($key);
            $state = (object) ['hash' => hash_init('sha256'), 'bytes' => 0, 'expected' => $size, 'heartbeat' => $heartbeat];
            MigrationIntegrityFilter::attach($input, $state);
            try {
                if (! $target->writeStream($targetKey, $input, ['visibility' => 'private', 'ContentType' => 'application/octet-stream', 'fms_encryption_heartbeat' => $heartbeat])) {
                    throw new EncryptionFailure('Encrypted copy was rejected.');
                }
            } finally {
                fclose($input);
            }
            $sha = hash_final($state->hash);
            if ($state->bytes !== $size || ($expectedHash !== null && ! hash_equals($expectedHash, $sha))) {
                throw new EncryptionFailure('Source bytes do not match the catalogue.');
            }
        } else {
            $size = (int) $copy->size_bytes;
            $sha = $copy->sha256;
            if (($expectedSize !== null && $expectedSize !== $size) || ($expectedHash !== null && ! hash_equals($expectedHash, $sha))) {
                throw new EncryptionFailure('The catalogue digest changed after copying.');
            }
        }
        $output = $target->readStream($targetKey);
        if (! is_resource($output)) {
            throw new EncryptionFailure('Copied object is unavailable.');
        }
        $verify = hash_init('sha256');
        $verification = (object) ['hash' => $verify, 'bytes' => 0, 'expected' => $size, 'heartbeat' => $heartbeat];
        MigrationIntegrityFilter::attach($output, $verification);
        try {
            while (! feof($output)) {
                $bytes = fread($output, 65536);
                if ($bytes === false || ($bytes === '' && ! feof($output))) {
                    throw new EncryptionFailure('Copy verification was interrupted.');
                }
            }
        } finally {
            fclose($output);
        }
        if ($verification->bytes !== $size || ! hash_equals($sha, hash_final($verify))) {
            throw new EncryptionFailure('Destination plaintext verification failed.');
        }
        if ($target instanceof AwsS3V3Adapter) {
            EncryptionProfiles::assertObjectMetadata($target->getConfig(), $target->getClient()->headObject(['Bucket' => $target->getConfig()['bucket'], 'Key' => $target->path($targetKey)]));
        }
        DB::transaction(function () use ($id, $lease, $copy, $targetKey, $sha, $size): void {
            $this->assertLease($id, $lease);
            if (! DB::table('fms_encryption_copies')->where('id', $copy->id)->where('target_key', $targetKey)->update(['sha256' => $sha, 'size_bytes' => $size, 'state' => $copy->state === 'published' ? 'published' : 'verified', 'updated_at' => now()])) {
                throw new EncryptionFailure('Migration copy allocation changed.');
            }
        });

        return DB::table('fms_encryption_copies')->where('id', $copy->id)->firstOrFail();
    }

    private function assertLease(string $id, string $lease): void
    {
        $operation = DB::table('fms_encryption_operations')->where('id', $id)->lockForUpdate()->firstOrFail();
        if ($operation->lease !== $lease || strtotime($operation->lease_until) <= time()) {
            throw new EncryptionFailure('Migration lease was lost.');
        }
    }

    private function target(string $disk): void
    {
        if (! in_array(config('filesystems.disks.'.$disk.'.driver'), ['fms-encrypted', 'fms-s3-native'], true)) {
            throw new EncryptionFailure('Select an explicitly application-encrypted or native encrypted target disk.');
        }
        Storage::disk($disk); // Fail before locking a workspace if keys/provider configuration are invalid.
    }
}
