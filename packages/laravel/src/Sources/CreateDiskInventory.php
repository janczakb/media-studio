<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sources;

use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class CreateDiskInventory extends Command
{
    protected $signature = 'media-studio:sources:inventory {workspace} {mount} {principal-type} {principal-id} {output} {--resume : Continue an interrupted S3 inventory} {--pages=100 : Maximum S3 listing pages per invocation}';

    protected $description = 'Stream a disk/S3 file inventory into a new private local JSONL file.';

    public function handle(WorkspaceAccess $access): int
    {
        $principal = new Principal((string) $this->argument('principal-type'), (string) $this->argument('principal-id'));
        abort_unless(is_string(config('media-studio.principals.'.$principal->type)), 422);
        $workspaceId = (string) $this->argument('workspace');
        $identity = DB::table('fms_workspaces')->where('public_id', $workspaceId)->first(['tenant_type', 'tenant_id', 'tenant_key']);
        abort_if($identity === null, 404);
        $workspace = $access->resolveForWorker($principal, $workspaceId, TenantIdentity::fromWorkspace($identity), write: true, operation: 'sources.sync');
        $mount = DiskMount::find((string) $this->argument('mount'));
        abort_unless($mount !== null && in_array($workspace->role, ['owner', 'admin'], true) && $mount->allows($workspace, $principal), 403);
        $disk = Storage::disk($mount->config['disk']);
        if ($disk instanceof AwsS3V3Adapter) {
            $authorize = function () use ($access, $principal, $workspaceId, $identity, $mount): void {
                $current = $access->resolveForWorker($principal, $workspaceId, TenantIdentity::fromWorkspace($identity), write: true, operation: 'sources.sync');
                $configured = DiskMount::find($mount->key);
                abort_unless($configured !== null && $configured->revision() === $mount->revision() && in_array($current->role, ['owner', 'admin'], true) && $configured->allows($current, $principal), 403);
            };
            $result = app(S3Inventory::class)->scan($disk, $mount, (string) $this->argument('output'), json_encode([$workspaceId, $principal->type, $principal->id], JSON_THROW_ON_ERROR), (bool) $this->option('resume'), (int) $this->option('pages'), $authorize);
            $this->info(($result['complete'] ? 'Inventory ready: ' : 'Inventory checkpoint saved; run again with --resume: ').$result['files'].' files.');

            return self::SUCCESS;
        }
        abort_if($this->option('resume'), 422, 'Resumable inventory is supported for S3 disks.');
        // Exclusive create: never overwrite an inventory being imported.
        $path = (string) $this->argument('output');
        $previousMask = umask(0077);
        try {
            $stream = @fopen($path, 'xb');
        } finally {
            umask($previousMask);
        }
        if (! is_resource($stream)) {
            $this->error('Output must be a new writable local file.');

            return self::INVALID;
        }
        $complete = false;
        $count = 0;
        try {
            flock($stream, LOCK_EX);
            $disk = Storage::disk($mount->config['disk']);
            // Flysystem yields storage pages; never call allFiles()/toArray()/sort().
            foreach ($disk->getDriver()->listContents(trim((string) ($mount->config['prefix'] ?? ''), '/'), true) as $item) {
                if (! $item->isFile()) {
                    continue;
                }
                $object = $mount->path($item->path());
                $line = json_encode(['path' => $object], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                if (fwrite($stream, $line) !== strlen($line)) {
                    throw new \RuntimeException('Inventory could not be written.');
                }
                $count++;
            }
            fflush($stream);
            $complete = true;
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
            if (! $complete) {
                unlink($path);
            }
        }
        $this->info('Inventory ready: '.$count.' files. Source objects unchanged.');

        return self::SUCCESS;
    }
}
