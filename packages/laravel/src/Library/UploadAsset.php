<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use UseFlex\MediaCore\Storage\StreamObjectStore;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\ActivityLog;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Intelligence\AnalysisAdmission;
use UseFlex\MediaStudio\Navigation\NavigationChanges;
use UseFlex\MediaStudio\Processing\ThumbnailProcessor;
use UseFlex\MediaStudio\Renditions\ImportedRenditions;
use UseFlex\MediaStudio\Search\ContentAdmission;
use UseFlex\MediaStudio\Storage\LaravelObjectStore;
use UseFlex\MediaStudio\Uploads\AcceptedFiles;
use UseFlex\MediaStudio\Uploads\UploadAttempts;

final class UploadAsset
{
    public function execute(object $workspace, Principal $principal, ?int $parentId, UploadedFile $file, string $key, ?callable $beforePublish = null, ?callable $afterPublish = null, ?Model $owner = null, ?callable $workspaceResolver = null, ?Principal $activityActor = null, ?ImportedRenditions $imported = null, bool $generateRenditions = true): object
    {
        $name = trim($file->getClientOriginalName());
        $this->validateName($name);
        $mime = $file->getMimeType() ?? 'application/octet-stream';
        AcceptedFiles::assertAllowed($workspace, $name, $mime);
        $sha = hash_file('sha256', $file->getPathname());
        $fingerprint = hash('sha256', json_encode([$principal->type, $principal->id, $parentId, $name, $sha, $mime, ...($owner === null ? [] : [$owner::class, $owner->getKey()]), ...($imported === null ? [] : [$imported->fingerprint()]), ...($generateRenditions ? [] : ['skip-renditions'])], JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $key);
        $root = trim((string) $workspace->storage_root, '/');
        abort_unless(preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9/_-]*\z~', $root), 500, 'Invalid managed storage root.');
        $disk = Storage::disk($workspace->disk);
        $attempts = app(UploadAttempts::class);
        $reservation = $attempts->begin($workspace, $principal, $keyHash, $fingerprint, $workspaceResolver, $imported?->presets() ?? []);
        if ($reservation['entry'] !== null) {
            return $reservation['entry'];
        }
        $attempt = $reservation['attempt'];
        $versionId = $attempt->id;
        $objectKey = $attempt->object_key;
        $stream = null;

        try {
            $stream = fopen($file->getPathname(), 'rb');
            $store = new StreamObjectStore(new LaravelObjectStore($disk));
            $store->write($objectKey, $stream);
            $imported?->write($attempt, $store);

            return $this->publishVerifiedObject(
                $workspace, $principal, $parentId, $name, $mime, (int) $file->getSize(), $sha, $versionId, $objectKey,
                function () use ($attempts, $attempt, $beforePublish): void {
                    $attempts->assertCurrent($attempt);
                    if ($beforePublish !== null) {
                        $beforePublish();
                    }
                },
                function (object $entry) use ($attempts, $attempt, $afterPublish, $imported): void {
                    $imported?->publish($attempt, $entry);
                    $attempts->accept($attempt, $entry);
                    if ($afterPublish !== null) {
                        $afterPublish($entry);
                    }
                },
                $owner, $workspaceResolver, $activityActor, generateRenditions: $generateRenditions && $imported === null,
            );
        } catch (Throwable $error) {
            try {
                $attempts->fail($attempt);
                $attempts->cleanup($attempt->id);
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }

            if ($error instanceof UniqueConstraintViolationException) {
                abort(409, 'A file with this name already exists in this folder.');
            }

            throw $error;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Publish an already verified object. The transport owns the object until
     * this transaction commits and remains responsible for unpublished cleanup.
     */
    public function publishVerifiedObject(
        object $workspace,
        Principal $principal,
        ?int $parentId,
        string $name,
        string $mime,
        int $size,
        string $sha256,
        string $versionId,
        string $objectKey,
        ?callable $beforePublish = null,
        ?callable $afterPublish = null,
        ?Model $owner = null,
        ?callable $workspaceResolver = null,
        ?Principal $activityActor = null,
        bool $generateRenditions = true,
    ): object {
        $this->validateName($name);
        $prefix = trim((string) $workspace->storage_root, '/').'/'.$workspace->public_id.'/originals/';
        abort_unless(Str::isUlid($versionId) && $objectKey === $prefix.$versionId && preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9/_-]*\z~', $objectKey), 422, 'Invalid managed object identity.');
        abort_unless($size > 0 && preg_match('/\A[a-f0-9]{64}\z/', $sha256), 422, 'Invalid verified file.');

        try {
            return DB::transaction(function () use ($workspace, $principal, $parentId, $name, $mime, $size, $sha256, $versionId, $objectKey, $beforePublish, $afterPublish, $owner, $workspaceResolver, $activityActor, $generateRenditions): object {
                $current = $workspaceResolver !== null ? $workspaceResolver(true) : app(WorkspaceAccess::class)->resolve($principal, $workspace->public_id, write: true, lock: true);
                AcceptedFiles::assertAllowed($current, $name, $mime);
                abort_unless($current->disk === $workspace->disk && $current->storage_root === $workspace->storage_root, 409, 'The workspace storage changed during the upload.');
                if ($parentId !== null) {
                    $parent = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $parentId)->where('kind', 'folder')->whereNull('trashed_at')->first(['id', 'source_kind']);
                    abort_if($parent === null, 409, 'The destination folder is no longer available.');
                    app(SourcePolicy::class)->managed($parent);
                }
                if ($beforePublish !== null) {
                    $beforePublish();
                }
                $entryId = DB::table('fms_entries')->insertGetId([
                    'public_id' => (string) Str::ulid(), 'workspace_id' => $workspace->id,
                    'parent_id' => $parentId, 'parent_scope' => $parentId ?? 0,
                    'kind' => 'file', 'sort_group' => 1, 'name' => $name,
                    'normalized_name' => mb_strtolower($name, 'UTF-8'),
                    'media_type' => explode('/', $mime)[0], 'mime_type' => $mime,
                    'size_bytes' => $size, 'current_version_id' => $versionId,
                    'uploader_key' => $activityActor === null ? $principal->key() : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('fms_asset_versions')->insert([
                    'id' => $versionId, 'entry_id' => $entryId, 'disk' => $workspace->disk,
                    'object_key' => $objectKey, 'sha256' => $sha256, 'size_bytes' => $size,
                    'mime_type' => $mime, 'rendition_model' => $owner === null ? null : $owner::class, 'created_at' => now(),
                ]);
                $entry = DB::table('fms_entries')->where('id', $entryId)->first();
                if ($afterPublish !== null) {
                    $afterPublish($entry);
                }
                app(AnalysisAdmission::class)->schedule($current, $entry, $principal);
                app(ContentAdmission::class)->schedule($current, $entry, $principal);
                if ($generateRenditions) {
                    app(ThumbnailProcessor::class)->schedule($workspace, $entry, $principal, $owner);
                }
                app(ActivityLog::class)->record($current, $activityActor ?? $principal, $entry, $activityActor === null ? 'uploaded' : 'guest_uploaded', $versionId, $activityActor === null ? null : $principal);

                app(NavigationChanges::class)->entries((int) $workspace->id, 'created', [$entry]);

                return DB::table('fms_entries')->where('id', $entryId)->first();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'A file with this name or version already exists.');
        }
    }

    public function validateName(string $name): void
    {
        abort_if($name === '' || mb_strlen($name) > 191 || in_array($name, ['.', '..'], true)
            || str_contains($name, '/') || str_contains($name, '\\') || preg_match('/\p{C}/u', $name), 422, 'Invalid file or folder name.');
    }
}
