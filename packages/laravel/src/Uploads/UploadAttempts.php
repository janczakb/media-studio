<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Uploads;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class UploadAttempts
{
    /** @return array{entry: ?object, attempt: ?object} */
    public function begin(object $workspace, Principal $principal, string $keyHash, string $fingerprint, ?callable $workspaceResolver = null, array $variantPresets = []): array
    {
        return DB::transaction(function () use ($workspace, $principal, $keyHash, $fingerprint, $workspaceResolver, $variantPresets): array {
            $current = $workspaceResolver !== null ? $workspaceResolver(true) : app(WorkspaceAccess::class)->resolve($principal, $workspace->public_id, write: true, lock: true);
            abort_unless($workspace->disk === $current->disk && $workspace->storage_root === $current->storage_root, 409, 'The workspace storage changed during the upload.');
            $receipts = DB::table('fms_upload_receipts')->where('workspace_id', $workspace->id)->where('key_hash', $keyHash);
            $receipt = (clone $receipts)->lockForUpdate()->first();
            if ($receipt === null) {
                $receiptId = DB::table('fms_upload_receipts')->insertGetId([
                    'workspace_id' => $workspace->id, 'key_hash' => $keyHash, 'fingerprint' => $fingerprint,
                    'state' => 'writing', 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                abort_unless(hash_equals($receipt->fingerprint, $fingerprint), 409, 'Upload key was used for a different request.');
                if ($receipt->state === 'accepted') {
                    $entry = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $receipt->entry_id)->first();
                    abort_if($entry === null, 410, 'The accepted file no longer exists.');

                    return ['entry' => $entry, 'attempt' => null];
                }
                $this->retirePrevious($receipt);
                $receiptId = $receipt->id;
            }
            $id = (string) Str::ulid();
            $leaseExpiresAt = now()->addSeconds($this->leaseSeconds());
            $root = trim((string) $workspace->storage_root, '/');
            abort_unless(preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9/_-]*\z~', $root), 500, 'Invalid managed storage root.');
            $key = $root.'/'.$workspace->public_id.'/originals/'.$id;
            DB::table('fms_upload_attempts')->insert([
                'id' => $id, 'receipt_id' => $receiptId, 'workspace_public_id' => $workspace->public_id,
                'storage_root' => $root, 'disk' => $workspace->disk, 'object_key' => $key,
                'storage_target' => $this->target($workspace->disk, $key), 'state' => 'writing',
                'variant_presets' => $variantPresets === [] ? null : json_encode($variantPresets, JSON_THROW_ON_ERROR),
                'lease_token' => bin2hex(random_bytes(32)), 'lease_expires_at' => $leaseExpiresAt,
                'next_cleanup_at' => $leaseExpiresAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $receipts->update(['state' => 'writing', 'current_attempt_id' => $id, 'updated_at' => now()]);

            return ['entry' => null, 'attempt' => DB::table('fms_upload_attempts')->where('id', $id)->first()];
        }, 3);
    }

    public function assertCurrent(object $attempt): void
    {
        $receipt = DB::table('fms_upload_receipts')->where('id', $attempt->receipt_id)->lockForUpdate()->first();
        $current = DB::table('fms_upload_attempts')->where('id', $attempt->id)->lockForUpdate()->first();
        abort_unless($receipt !== null && $receipt->state === 'writing' && $receipt->current_attempt_id === $attempt->id
            && $current !== null && $current->state === 'writing' && hash_equals($current->lease_token, $attempt->lease_token)
            && strtotime($current->lease_expires_at) > now()->getTimestamp(), 409, 'Upload write lease expired or was replaced.');
        abort_unless(hash_equals($attempt->storage_target, $this->target($attempt->disk, $attempt->object_key)), 409, 'The upload storage target changed before publication.');
    }

    public function accept(object $attempt, object $entry): void
    {
        DB::table('fms_upload_attempts')->where('id', $attempt->id)->update([
            'state' => 'accepted', 'writer_finished_at' => now(), 'next_cleanup_at' => null, 'updated_at' => now(),
        ]);
        DB::table('fms_upload_receipts')->where('id', $attempt->receipt_id)->where('current_attempt_id', $attempt->id)->update([
            'entry_id' => $entry->id, 'state' => 'accepted', 'updated_at' => now(),
        ]);
    }

    public function fail(object $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            $receipt = DB::table('fms_upload_receipts')->where('id', $attempt->receipt_id)->lockForUpdate()->first();
            $current = DB::table('fms_upload_attempts')->where('id', $attempt->id)->lockForUpdate()->first();
            if ($current === null || $current->cleaned_at !== null || in_array($current->state, ['accepted', 'retained'], true)) {
                return;
            }
            DB::table('fms_upload_attempts')->where('id', $attempt->id)->update([
                'state' => 'failed', 'writer_finished_at' => now(), 'next_cleanup_at' => now(), 'updated_at' => now(),
            ]);
            if ($receipt !== null && $receipt->state === 'writing' && $receipt->current_attempt_id === $attempt->id) {
                DB::table('fms_upload_receipts')->where('id', $receipt->id)->update(['state' => 'failed', 'updated_at' => now()]);
            }
        });
    }

    public function cleanup(string $id): bool
    {
        $attempt = DB::transaction(function () use ($id): ?object {
            $hint = DB::table('fms_upload_attempts')->where('id', $id)->first(['receipt_id']);
            if ($hint === null) {
                return null;
            }
            $receipt = $hint->receipt_id === null ? null : DB::table('fms_upload_receipts')->where('id', $hint->receipt_id)->lockForUpdate()->first();
            $row = DB::table('fms_upload_attempts')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->cleaned_at !== null || ! in_array($row->state, ['writing', 'failed', 'abandoned'], true)
                || $row->next_cleanup_at === null || strtotime($row->next_cleanup_at) > now()->getTimestamp()) {
                return null;
            }
            if ($row->state === 'writing') {
                if (strtotime($row->lease_expires_at) > now()->getTimestamp()) {
                    return null;
                }
                $row->state = 'abandoned';
                if ($receipt !== null && $receipt->state === 'writing' && $receipt->current_attempt_id === $id) {
                    DB::table('fms_upload_receipts')->where('id', $receipt->id)->update(['state' => 'failed', 'updated_at' => now()]);
                }
            }
            DB::table('fms_upload_attempts')->where('id', $id)->update([
                'state' => $row->state, 'next_cleanup_at' => now()->addSeconds($this->cleanupRetrySeconds()),
                'cleanup_attempts' => $row->cleanup_attempts + 1, 'updated_at' => now(),
            ]);

            return $row;
        }, 3);
        if ($attempt === null) {
            return false;
        }
        abort_unless(Str::isUlid($attempt->id) && Str::isUlid($attempt->workspace_public_id)
            && preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9/_-]*\z~', $attempt->object_key)
            && $attempt->object_key === $attempt->storage_root.'/'.$attempt->workspace_public_id.'/originals/'.$attempt->id,
            409, 'The abandoned upload has an invalid storage identity.');
        $referenced = DB::table('fms_asset_versions')->where('id', $attempt->id)->exists();
        if ($referenced) {
            DB::table('fms_upload_attempts')->where('id', $id)->update(['state' => 'retained', 'cleaned_at' => now(), 'next_cleanup_at' => null, 'updated_at' => now()]);

            return false;
        }
        abort_unless(hash_equals($attempt->storage_target, $this->target($attempt->disk, $attempt->object_key)), 409, 'The abandoned upload storage target changed.');
        $keys = [$attempt->object_key];
        $presets = json_decode($attempt->variant_presets ?? '[]', true, 4, JSON_THROW_ON_ERROR);
        abort_unless(is_array($presets) && count($presets) <= 12, 409);
        foreach ($presets as $preset) {
            $keys[] = self::variantKey($attempt, $preset);
        }
        if (! Storage::disk($attempt->disk)->delete(count($keys) === 1 ? $keys[0] : $keys)) {
            throw new RuntimeException('The storage provider could not remove the abandoned upload.');
        }
        // An expired writer can finish after this deletion. Keep its tombstone scheduled until it reports completion.
        if ($attempt->writer_finished_at !== null) {
            DB::table('fms_upload_attempts')->where('id', $id)->update(['cleaned_at' => now(), 'next_cleanup_at' => null, 'updated_at' => now()]);
        }

        return true;
    }

    public static function variantKey(object $attempt, string $preset): string
    {
        abort_unless(preg_match('/\Aimport-[a-z][a-z0-9_-]{0,22}\z/', $preset)
            && preg_match('/\A[a-f0-9]{64}\z/', $attempt->lease_token), 409);

        return $attempt->storage_root.'/'.$attempt->workspace_public_id.'/renditions/'.$attempt->id.'/'.substr($attempt->lease_token, 0, 32).'/'.$preset;
    }

    private function retirePrevious(object $receipt): void
    {
        $previous = $receipt->current_attempt_id === null ? null
            : DB::table('fms_upload_attempts')->where('id', $receipt->current_attempt_id)->lockForUpdate()->first();
        if ($receipt->state === 'writing') {
            if ($previous === null) {
                abort_if(strtotime($receipt->updated_at) + $this->leaseSeconds() > now()->getTimestamp(), 409, 'Upload is already in progress.');
            } else {
                abort_if($previous->state === 'writing' && strtotime($previous->lease_expires_at) > now()->getTimestamp(), 409, 'Upload is already in progress.');
            }
        }
        abort_unless(in_array($receipt->state, ['writing', 'failed'], true), 409, 'Upload cannot be retried in this state.');
        if ($previous !== null && $previous->state === 'writing') {
            DB::table('fms_upload_attempts')->where('id', $previous->id)->update(['state' => 'abandoned', 'next_cleanup_at' => now(), 'updated_at' => now()]);
        }
    }

    private function target(string $diskName, string $key): string
    {
        $disk = Storage::disk($diskName);
        $config = array_intersect_key($disk->getConfig(), array_flip(['driver', 'root', 'prefix', 'bucket', 'endpoint', 'region', 'use_path_style_endpoint']));
        ksort($config);

        return hash('sha256', json_encode([$config, $disk->path($key)], JSON_THROW_ON_ERROR));
    }

    private function leaseSeconds(): int
    {
        return max(30, min(86400, (int) config('media-studio.upload.receipts.lease_seconds', 300)));
    }

    private function cleanupRetrySeconds(): int
    {
        return max(60, min(86400, (int) config('media-studio.upload.receipts.cleanup_retry_seconds', 3600)));
    }
}
