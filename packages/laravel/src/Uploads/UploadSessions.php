<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Uploads;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaCore\Transfers\LocalPartStore;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Library\UploadAsset;

final class UploadSessions
{
    public function __construct(private WorkspaceAccess $access, private Entries $entries, private UploadAsset $uploads, private S3Multipart $multipart) {}

    public function policy(?object $workspace = null): array
    {
        $stored = json_decode($workspace->studio_settings ?? '{}', true);
        $enabled = (bool) config('media-studio.upload.sessions.enabled', false) && ($stored['chunks'] ?? true);
        $serverMax = (int) config('media-studio.upload.sessions.max_bytes', 10737418240);
        $workspaceMax = $stored['chunkMaxMiB'] ?? null;

        return [
            'enabled' => $enabled,
            'accept' => $workspace === null ? null : implode(',', array_map(static fn (string $extension): string => '.'.$extension, AcceptedFiles::extensions($workspace))),
            'chunkBytes' => (int) config('media-studio.upload.sessions.chunk_bytes', 5242880),
            'maxBytes' => is_int($workspaceMax) && $workspaceMax > 0 && $workspaceMax < intdiv(PHP_INT_MAX, 1048576)
                ? min($serverMax, $workspaceMax * 1048576) : $serverMax,
            'maxParts' => (int) config('media-studio.upload.sessions.max_parts', 10000),
            'maxConcurrent' => (int) config('media-studio.upload.sessions.max_concurrent', 3),
            'ordinaryMaxBytes' => (int) config('media-studio.upload.max_kilobytes', 25600) * 1024,
            'multipartThresholdBytes' => $workspace === null || ! $enabled ? null : $this->multipart->threshold($workspace),
        ];
    }

    public function store(): LocalPartStore
    {
        return new LocalPartStore((string) config('media-studio.upload.sessions.staging_directory', storage_path('app/media-studio-upload-parts')));
    }

    public function create(Principal $principal, string $workspaceId, array $data, ?string $clientScope = null): array
    {
        $policy = $this->policy();
        abort_unless($policy['enabled'], 403, 'Chunk uploads are disabled.');
        abort_if($data['size'] > $policy['maxBytes'], 422, 'File exceeds the upload policy.');
        $this->uploads->validateName($data['name']);

        try {
            $row = DB::transaction(function () use ($principal, $workspaceId, $data, $clientScope): object {
                $workspace = $this->access->resolve($principal, $workspaceId, write: true, lock: true);
                $policy = $this->policy($workspace);
                abort_unless($policy['enabled'], 403, 'Chunk uploads are disabled for this workspace.');
                AcceptedFiles::assertName($workspace, $data['name']);
                $parent = $this->entries->parentId($workspace->id, $data['parent'] ?? null);
                $fingerprint = hash('sha256', json_encode([$parent, $data['name'], $data['size']], JSON_THROW_ON_ERROR));
                $key = $clientScope === null ? $data['key'] : $clientScope.':'.$data['key'];
                $existing = DB::table('fms_upload_sessions')->where('workspace_id', $workspace->id)->where('principal_key', $principal->key())->where('client_scope', $clientScope)->where('key_hash', hash('sha256', $key))->first();
                if ($existing !== null) {
                    abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'Upload key belongs to a different file.');
                    $this->ensureActive($existing);

                    return $existing;
                }
                abort_if($data['size'] > $policy['maxBytes'], 422, 'File exceeds the workspace chunk upload limit.');
                $threshold = ($data['direct'] ?? false) ? $this->multipart->threshold($workspace) : null;
                $direct = $threshold !== null && $data['size'] >= $threshold;
                $chunkBytes = $direct ? $this->multipart->chunkBytes() : $policy['chunkBytes'];
                abort_unless($chunkBytes >= 65536 && $chunkBytes <= 33554432, 500, 'Invalid chunk size configuration.');
                $partCount = (int) ceil($data['size'] / $chunkBytes);
                abort_if($partCount > ($direct ? min(10000, $policy['maxParts']) : $policy['maxParts']), 422, 'File exceeds the upload policy.');
                $count = DB::table('fms_upload_sessions')->where('workspace_id', $workspace->id)->where('principal_key', $principal->key())->whereIn('state', ['uploading', 'initializing', 'finalizing'])->where('expires_at', '>', now())->count();
                abort_if($count >= (int) config('media-studio.upload.sessions.max_active', 20), 429, 'Too many active uploads.');
                $reserved = DB::table('fms_upload_sessions')->where('workspace_id', $workspace->id)->whereNull('cleaned_at')
                    ->where(function ($query): void {
                        $query->where(fn ($active) => $active->whereIn('state', ['uploading', 'initializing', 'finalizing'])->where('expires_at', '>', now()))
                            ->orWhere(fn ($remote) => $remote->where('transport', S3Multipart::TRANSPORT)->whereNotNull('provider_upload_id'));
                    })->sum('size_bytes');
                abort_if($reserved + $data['size'] > (int) config('media-studio.upload.sessions.max_reserved_bytes', 53687091200), 429, 'Workspace upload staging capacity is reserved by other uploads.');
                $storage = [];
                if ($direct) {
                    $versionId = (string) Str::ulid();
                    $root = trim((string) $workspace->storage_root, '/');
                    abort_unless(preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9/_-]*\z~', $root), 500, 'Invalid managed storage root.');
                    $objectKey = $root.'/'.$workspaceId.'/originals/'.$versionId;
                    $storage = [
                        'transport' => S3Multipart::TRANSPORT, 'disk' => $workspace->disk, 'version_id' => $versionId, 'object_key' => $objectKey,
                        'storage_target' => $this->multipart->target($workspace->disk, $objectKey),
                    ];
                }
                $id = DB::table('fms_upload_sessions')->insertGetId([
                    'public_id' => (string) Str::ulid(), 'workspace_id' => $workspace->id, 'principal_key' => $principal->key(), 'key_hash' => hash('sha256', $key), 'client_scope' => $clientScope, 'fingerprint' => $fingerprint,
                    'parent_id' => $parent, 'name' => $data['name'], 'size_bytes' => $data['size'], 'chunk_bytes' => $chunkBytes, 'part_count' => $partCount,
                    'state' => 'uploading', 'expires_at' => now()->addSeconds((int) config('media-studio.upload.sessions.ttl_seconds', 86400)), 'created_at' => now(), 'updated_at' => now(),
                    ...$storage,
                ]);

                return DB::table('fms_upload_sessions')->where('id', $id)->first();
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Upload session creation is already in progress.');
        }

        $row->workspace_public_id = $workspaceId;

        return $this->card($row);
    }

    public function resolve(Principal $principal, string $workspaceId, string $sessionId, bool $lock = false, ?string $clientScope = null): object
    {
        $workspace = $this->access->resolve($principal, $workspaceId, write: true);
        $query = DB::table('fms_upload_sessions')->where('workspace_id', $workspace->id)->where('principal_key', $principal->key())->where('public_id', $sessionId)->where('client_scope', $clientScope);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($row === null, 404);
        $this->ensureActive($row);
        if ($row->transport === S3Multipart::TRANSPORT && $row->state !== 'completed') {
            abort_unless($row->disk === $workspace->disk && $row->object_key === trim($workspace->storage_root, '/').'/'.$workspaceId.'/originals/'.$row->version_id, 409, 'Upload destination changed after this session was created.');
        }

        $row->workspace_public_id = $workspaceId;

        return $row;
    }

    public function card(object $row): array
    {
        $entry = $row->entry_id === null ? null : DB::table('fms_entries')->where('workspace_id', $row->workspace_id)->where('id', $row->entry_id)->first();
        if ($entry !== null) {
            $entry->workspace_public_id = $row->workspace_public_id ?? null;
        }

        return ['id' => $row->public_id, 'state' => $row->state, 'transport' => $row->transport, 'chunkBytes' => $row->chunk_bytes, 'size' => $row->size_bytes,
            'acceptedParts' => DB::table('fms_upload_parts')->where('session_id', $row->id)
                ->when($row->transport === S3Multipart::TRANSPORT, fn ($query) => $query->whereNotNull('provider_etag'))
                ->orderBy('part_index')->pluck('part_index')->all(),
            'entry' => $entry === null ? null : $this->entries->card($entry)];
    }

    public function signPart(Principal $principal, string $workspaceId, string $sessionId, int $index, string $sha256, ?string $clientScope = null): array
    {
        $token = bin2hex(random_bytes(32));
        $row = DB::transaction(function () use ($principal, $workspaceId, $sessionId, $index, $sha256, $token, $clientScope): object {
            $row = $this->resolve($principal, $workspaceId, $sessionId, lock: true, clientScope: $clientScope);
            abort_if($row->state === 'initializing' && strtotime($row->lease_expires_at) > time(), 503, 'Multipart storage is being initialized.');
            abort_unless(in_array($row->state, ['uploading', 'initializing'], true), 409, 'Upload part is not accepted in this state.');
            $size = $this->multipartPartSize($row, $index);
            $existing = DB::table('fms_upload_parts')->where('session_id', $row->id)->where('part_index', $index)->first();
            if ($existing === null) {
                DB::table('fms_upload_parts')->insert(['session_id' => $row->id, 'part_index' => $index, 'size_bytes' => $size, 'sha256' => $sha256]);
            } else {
                abort_unless(hash_equals($existing->sha256, $sha256), 409, 'A different part was already reserved at this offset.');
            }
            if ($row->provider_upload_id === null) {
                DB::table('fms_upload_sessions')->where('id', $row->id)->update([
                    'state' => 'initializing', 'lease_token' => $token, 'lease_expires_at' => now()->addMinutes(2), 'updated_at' => now(),
                ]);
            }

            return $row;
        });
        if ($row->provider_upload_id === null) {
            $row->provider_upload_id = $this->initializeMultipart($row, $token);
            $row = $this->resolve($principal, $workspaceId, $sessionId, clientScope: $clientScope);
        }

        return $this->multipart->sign($row, $index, $this->multipartPartSize($row, $index), $sha256);
    }

    public function recordPart(Principal $principal, string $workspaceId, string $sessionId, int $index, string $etag, ?string $clientScope = null): void
    {
        DB::transaction(function () use ($principal, $workspaceId, $sessionId, $index, $etag, $clientScope): void {
            $row = $this->resolve($principal, $workspaceId, $sessionId, lock: true, clientScope: $clientScope);
            $this->multipartPartSize($row, $index);
            abort_unless($row->state === 'uploading' && $row->provider_upload_id !== null, 409, 'Upload part is not accepted in this state.');
            $part = DB::table('fms_upload_parts')->where('session_id', $row->id)->where('part_index', $index);
            $existing = $part->first();
            abort_if($existing === null, 409, 'This upload part has no reservation.');
            $part->update(['provider_etag' => $etag]);
            if ($existing->provider_etag === null) {
                DB::table('fms_upload_sessions')->where('id', $row->id)->increment('received_bytes', $existing->size_bytes, ['updated_at' => now()]);
            }
        });
    }

    private function initializeMultipart(object $row, string $token): string
    {
        $claim = DB::table('fms_upload_sessions')->where('id', $row->id)->where('state', 'initializing')->where('lease_token', $token);
        $providerId = null;
        try {
            $providerId = $this->multipart->start($row);
            $stored = (clone $claim)->where('expires_at', '>', now())->where('lease_expires_at', '>', now())->update([
                'provider_upload_id' => $providerId, 'state' => 'uploading', 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
            ]);
            abort_unless($stored === 1, 409, 'Multipart initialization lease expired.');

            return $providerId;
        } catch (\Throwable $error) {
            if ($providerId !== null) {
                try {
                    $this->multipart->abortProviderUpload($row, $providerId);
                } catch (\Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            $claim->update(['state' => 'uploading', 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);

            throw $error;
        }
    }

    private function multipartPartSize(object $row, int $index): int
    {
        abort_unless($row->transport === S3Multipart::TRANSPORT && $index >= 0 && $index < $row->part_count, 409, 'Upload part is outside the multipart session.');

        return min($row->chunk_bytes, $row->size_bytes - $index * $row->chunk_bytes);
    }

    private function ensureActive(object $row): void
    {
        abort_if(in_array($row->state, ['expired', 'cancelled'], true) || ($row->state !== 'completed' && strtotime($row->expires_at) <= time()), 410, 'Upload session expired or was cancelled.');
    }

    /** @param resource $input */
    public function part(Principal $principal, string $workspaceId, string $sessionId, int $index, mixed $input, ?string $expectedHash, ?string $clientScope = null): void
    {
        DB::transaction(function () use ($principal, $workspaceId, $sessionId, $index, $input, $expectedHash, $clientScope): void {
            $row = $this->resolve($principal, $workspaceId, $sessionId, lock: true, clientScope: $clientScope);
            abort_unless($row->transport === 'local' && $row->state === 'uploading' && $index >= 0 && $index < $row->part_count, 409, 'Upload part is not accepted in this state.');
            $expectedSize = min($row->chunk_bytes, $row->size_bytes - $index * $row->chunk_bytes);
            $existing = DB::table('fms_upload_parts')->where('session_id', $row->id)->where('part_index', $index)->first();
            // Hash an existing retry without replacing the accepted object.
            if ($existing !== null) {
                $hash = hash_init('sha256');
                $size = hash_update_stream($hash, $input, $expectedSize + 1);
                $sha = hash_final($hash);
                abort_unless($size === $existing->size_bytes && hash_equals($existing->sha256, $sha) && ($expectedHash === null || hash_equals($expectedHash, $sha)), 409, 'A different part was already accepted at this offset.');

                return;
            }
            try {
                $part = $this->store()->write($sessionId, $index, $input, $expectedSize);
            } catch (\InvalidArgumentException $error) {
                abort(422, $error->getMessage());
            }
            if ($expectedHash !== null) {
                abort_unless(hash_equals($expectedHash, $part['sha256']), 422, 'Upload part checksum mismatch.');
            }
            DB::table('fms_upload_parts')->insert(['session_id' => $row->id, 'part_index' => $index, 'size_bytes' => $part['size'], 'sha256' => $part['sha256']]);
            DB::table('fms_upload_sessions')->where('id', $row->id)->increment('received_bytes', $part['size'], ['updated_at' => now()]);
        });
    }

    public function complete(Principal $principal, string $workspaceId, string $sessionId, ?string $clientScope = null, ?callable $authorizePublish = null): object
    {
        $token = bin2hex(random_bytes(32));
        $row = DB::transaction(function () use ($principal, $workspaceId, $sessionId, $token, $clientScope): object {
            $row = $this->resolve($principal, $workspaceId, $sessionId, lock: true, clientScope: $clientScope);
            if ($row->state === 'completed') {
                return $row;
            }
            $direct = $row->transport === S3Multipart::TRANSPORT;
            $retry = $direct && $row->state === 'finalizing' && strtotime($row->lease_expires_at) <= time();
            abort_unless($row->state === 'uploading' || $retry, 409, 'Upload finalization is already in progress.');
            abort_unless($row->received_bytes === $row->size_bytes, 409, 'Upload is incomplete.');
            $parts = DB::table('fms_upload_parts')->where('session_id', $row->id);
            if ($direct) {
                abort_if($row->provider_upload_id === null, 409, 'Upload is incomplete.');
                $parts->whereNotNull('provider_etag');
            }
            abort_unless($parts->count() === $row->part_count, 409, 'Upload is incomplete.');
            $leaseExpiresAt = now()->addSeconds((int) config('media-studio.upload.sessions.lock_seconds', 3600));
            DB::table('fms_upload_sessions')->where('id', $row->id)->update([
                'state' => 'finalizing', 'lease_token' => $token,
                'lease_expires_at' => $leaseExpiresAt,
                'updated_at' => now(),
            ]);
            $row->lease_expires_at = $leaseExpiresAt->toDateTimeString();

            return $row;
        });
        if ($row->state === 'completed') {
            $entry = DB::table('fms_entries')->where('workspace_id', $row->workspace_id)->where('id', $row->entry_id)->first();
            abort_if($entry === null, 410, 'The accepted upload no longer exists.');

            $entry->workspace_public_id = $workspaceId;

            return $entry;
        }
        $path = null;
        $beforePublish = function () use ($row, $token, $authorizePublish): void {
            if ($authorizePublish !== null) {
                $authorizePublish();
            }
            $current = DB::table('fms_upload_sessions')->where('id', $row->id)->lockForUpdate()->first();
            abort_unless($current !== null && $current->state === 'finalizing' && hash_equals((string) $current->lease_token, $token)
                && strtotime($current->lease_expires_at) > time(), 409, 'Upload finalization lease expired.');
            if ($row->transport === S3Multipart::TRANSPORT) {
                $this->ensureActive($current);
                abort_unless(hash_equals($row->storage_target, $this->multipart->target($row->disk, $row->object_key)), 409, 'Multipart storage changed before publication.');
            }
        };
        $afterPublish = function (object $entry) use ($row): void {
            DB::table('fms_upload_sessions')->where('id', $row->id)->update([
                'entry_id' => $entry->id, 'state' => 'completed', 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
                ...($row->transport === S3Multipart::TRANSPORT ? ['cleaned_at' => now()] : []),
            ]);
        };
        try {
            // No database transaction or lock is held while streaming parts or the original.
            $parts = DB::table('fms_upload_parts')->where('session_id', $row->id)->orderBy('part_index')->get();
            if ($row->transport === S3Multipart::TRANSPORT) {
                abort_if($row->size_bytes > $this->policy()['maxBytes'], 422, 'Upload size does not match the policy.');
                $this->multipart->finish($row, $parts->all());
                $verified = $this->multipart->verify($row, $parts->all());
                $workspace = $this->access->resolve($principal, $workspaceId, write: true);
                abort_unless($workspace->disk === $row->disk, 409, 'Upload destination changed after this session was created.');
                $entry = $this->uploads->publishVerifiedObject($workspace, $principal, $row->parent_id, $row->name, $verified['mime'], $row->size_bytes, $verified['sha256'], $row->version_id, $row->object_key, $beforePublish, $afterPublish);
                $entry->workspace_public_id = $workspaceId;

                return $entry;
            }
            $path = $this->store()->assemble($sessionId, $parts);
            $file = new UploadedFile($path, $row->name, null, null, true);
            abort_unless($file->getSize() === $row->size_bytes && $file->getSize() <= $this->policy()['maxBytes'], 422, 'Upload size does not match the policy.');
            $workspace = $this->access->resolve($principal, $workspaceId, write: true);
            $entry = $this->uploads->execute(
                $workspace, $principal, $row->parent_id, $file, 'chunk-session-'.$sessionId,
                $beforePublish,
                $afterPublish,
            );
            try {
                $this->store()->clear($sessionId);
                DB::table('fms_upload_sessions')->where('id', $row->id)->update(['cleaned_at' => now()]);
            } catch (\Throwable $error) {
                report($error);
            }

            $entry->workspace_public_id = $workspaceId;

            return $entry;
        } catch (\Throwable $error) {
            DB::table('fms_upload_sessions')->where('id', $row->id)->where('state', 'finalizing')->where('lease_token', $token)
                ->update(['state' => 'uploading', 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
            throw $error;
        } finally {
            if ($path !== null && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function cancel(Principal $principal, string $workspaceId, string $sessionId, ?string $clientScope = null): void
    {
        $row = DB::transaction(function () use ($principal, $workspaceId, $sessionId, $clientScope): object {
            $workspace = $this->access->resolve($principal, $workspaceId, write: true);
            $row = DB::table('fms_upload_sessions')->where('workspace_id', $workspace->id)->where('principal_key', $principal->key())->where('public_id', $sessionId)->where('client_scope', $clientScope)->lockForUpdate()->first();
            abort_if($row === null, 404);
            $finalizing = $row->state === 'finalizing' && ($row->transport !== S3Multipart::TRANSPORT || strtotime($row->lease_expires_at) > time());
            abort_if($row->state === 'completed' || $finalizing, 409, 'This upload has been accepted or is being finalized.');
            DB::table('fms_upload_sessions')->where('id', $row->id)->update([
                'state' => 'cancelled', 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
            ]);
            $row->state = 'cancelled';

            return $row;
        });
        try {
            $this->cleanup($row);
        } catch (\Throwable $error) {
            report($error);
        }
    }

    public function cleanup(object $row): bool
    {
        if ($row->cleaned_at !== null) {
            return false;
        }
        if ($row->transport === S3Multipart::TRANSPORT) {
            $referenced = DB::table('fms_asset_versions')->where('id', $row->version_id)
                ->where('disk', $row->disk)->where('object_key', $row->object_key)->exists();
            if (! $referenced) {
                $this->multipart->cleanup($row);
            }
        } else {
            $this->store()->clear($row->public_id);
        }

        return DB::transaction(function () use ($row): bool {
            DB::table('fms_upload_parts')->where('session_id', $row->id)->delete();

            return DB::table('fms_upload_sessions')->where('id', $row->id)->whereNull('cleaned_at')->update(['cleaned_at' => now()]) === 1;
        });
    }
}
