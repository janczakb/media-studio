<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sharing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class PrepareArchive implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public string $id) {}

    public function handle(WorkspaceAccess $access, Shares $shares, ArchiveDownload $archives): void
    {
        $job = DB::table('fms_archive_jobs')->where('id', $this->id)->first();
        $lease = bin2hex(random_bytes(16));
        if ($job === null || DB::table('fms_archive_jobs')->where('id', $this->id)->where('state', 'queued')->where('expires_at', '>', now())->update(['state' => 'processing', 'lease_token' => $lease, 'updated_at' => now()]) !== 1) {
            return;
        }
        $path = tempnam(sys_get_temp_dir(), 'fms-archive-');
        $key = 'flex-media-studio/archives/'.$this->id.'-'.bin2hex(random_bytes(16)).'.zip';
        try {
            DB::table('fms_archive_jobs')->where('id', $this->id)->where('state', 'processing')->where('lease_token', $lease)->update(['object_key' => $key, 'updated_at' => now()]);
            if ($path === false) {
                throw new \RuntimeException('Cannot create temporary archive.');
            }
            $principal = new Principal($job->principal_type, $job->principal_id);
            $raw = DB::table('fms_workspaces')->where('id', $job->workspace_id)->first();
            abort_if($raw === null, 404);
            $tenant = TenantIdentity::fromWorkspace($raw);
            $authorize = function () use ($access, $principal, $raw, $tenant, $lease, $job, $shares): object {
                abort_unless(DB::table('fms_archive_jobs')->where('id', $this->id)->where('state', 'processing')->where('lease_token', $lease)->where('expires_at', '>', now())->exists(), 409);
                if ($job->share_id !== null) {
                    $shares->enabled();
                    abort_unless(DB::table('fms_shares')->where('id', $job->share_id)->where('workspace_id', $raw->id)->where('revision', $job->share_revision)
                        ->where('creator_key', $principal->key())->where('allow_download', true)->where('allow_archive', true)
                        ->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists(), 403);
                }
                $workspace = $access->resolveForWorker($principal, $raw->public_id, $tenant, write: $job->share_id !== null, operation: 'files.download');
                if ($job->share_id !== null) {
                    $permissions = app(StudioPermissions::class);
                    $permissions->authorize($permissions->actor($principal), 'shares.manage', $workspace);
                    $ids = json_decode($job->entries, true, 512, JSON_THROW_ON_ERROR);
                    $roots = DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->whereNull('trashed_at')->get()->all();
                    $allowed = DB::table('fms_share_entries')->where('share_id', $job->share_id)->pluck('entry_id')->all();
                    abort_unless(count($roots) === count($ids) && count($shares->accessible($workspace, $principal, $roots, $allowed)) === count($roots), 404);
                }

                return $workspace;
            };
            $workspace = $authorize();
            $ids = json_decode($job->entries, true, 512, JSON_THROW_ON_ERROR);
            $roots = DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->whereNull('trashed_at')->get()->all();
            $shareRoots = $job->share_id === null ? null : DB::table('fms_share_entries')->where('share_id', $job->share_id)->pluck('entry_id')->all();
            abort_unless(count($roots) === count($ids) && count($shares->accessible($workspace, $principal, $roots, $shareRoots)) === count($roots), 404);
            $stream = fopen($path, 'w+b');
            if ($stream === false) {
                throw new \RuntimeException('Cannot open temporary archive.');
            }
            try {
                $limit = max(1, min(50000, (int) config('media-studio.downloads.background_max_entries', 50000)));
                $fingerprint = $archives->write($workspace, $principal, $roots, $authorize, $stream, $limit);
                rewind($stream);
                if (! Storage::disk($job->disk)->put($key, $stream, ['visibility' => 'private'])) {
                    throw new \RuntimeException('Cannot store archive.');
                }
            } finally {
                fclose($stream);
            }
            $authorize();
            abort_unless(hash_equals($fingerprint, $archives->fingerprint($workspace, $principal, $roots, $limit)), 409);
            $published = DB::table('fms_archive_jobs')->where('id', $this->id)->where('state', 'processing')->where('lease_token', $lease)->where('expires_at', '>', now())->update([
                'state' => 'ready', 'object_key' => $key, 'fingerprint' => $fingerprint,
                'size_bytes' => filesize($path), 'updated_at' => now(),
            ]);
            if ($published !== 1) {
                Storage::disk($job->disk)->delete($key);
            }
        } catch (\Throwable $exception) {
            Storage::disk($job->disk)->delete($key);
            DB::table('fms_archive_jobs')->where('id', $this->id)->where('state', 'processing')->where('lease_token', $lease)->update(['state' => 'failed', 'updated_at' => now()]);
            throw $exception;
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }
}
