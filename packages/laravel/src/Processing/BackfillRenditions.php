<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Processing;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\DocumentThumbnails\DocumentThumbnails;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class BackfillRenditions extends Command
{
    protected $signature = 'media-studio:renditions:backfill {workspace} {principal-type} {principal-id}
        {--source=managed : managed or opt-in disk sources}
        {--after=0 : Start after this internal entry ID} {--limit=100 : Maximum entries to scan}
        {--refresh : Regenerate existing native renditions as well}
        {--retention=keep : Keep replaced renditions or mark them for pruning}
        {--apply : Queue conversions instead of inspecting the batch}';

    protected $description = 'Generate conversions for managed media or opt-in disk images, preserving originals.';

    public function handle(WorkspaceAccess $access, ThumbnailProcessor $processor): int
    {
        $workspaceId = (string) $this->argument('workspace');
        $type = (string) $this->argument('principal-type');
        $id = (string) $this->argument('principal-id');
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if (! Str::isUlid($workspaceId) || ! is_string(config('media-studio.principals.'.$type)) || $id === '' || strlen($id) > 191 || $after === false || $limit === false) {
            $this->error('Provide a workspace, registered principal, limit between 1 and 1000, and non-negative cursor.');

            return self::INVALID;
        }

        $retention = $this->option('retention');
        if (! in_array($retention, ['keep', 'prune'], true) || ($retention === 'prune' && ! $this->option('refresh'))) {
            $this->error('Use keep or prune. Pruning requires --refresh.');

            return self::INVALID;
        }
        $source = $this->option('source');
        if (! in_array($source, ['managed', 'disk'], true)) {
            return self::INVALID;
        }
        $replace = $this->option('refresh') ? $retention : null;
        $principal = new Principal($type, $id);
        $identity = DB::table('fms_workspaces')->where('public_id', $workspaceId)->first(['tenant_type', 'tenant_id', 'tenant_key']);
        if ($identity === null) {
            $this->error('Workspace unavailable.');

            return self::FAILURE;
        }
        $tenant = TenantIdentity::fromWorkspace($identity);
        $workspace = $access->resolveForWorker($principal, $workspaceId, $tenant, write: true, operation: 'files.optimize');
        $rows = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', '>', $after)
            ->where('source_kind', $source)->whereNull('trashed_at')->orderBy('id')->limit($limit)->get();
        $eligible = $queued = 0;
        foreach ($rows as $row) {
            if ($source === 'disk') {
                try {
                    app(RenditionSource::class)->authorize($workspace, $principal, $row);
                } catch (HttpException $error) {
                    if (in_array($error->getStatusCode(), [403, 404], true)) {
                        continue;
                    }
                    throw $error;
                }
            }
            if (! $processor->supports($row) && app(DocumentThumbnails::class)->recipe($row) === null) {
                continue;
            }
            $eligible++;
            if ($this->option('apply')) {
                $queued += DB::transaction(function () use ($access, $principal, $workspaceId, $tenant, $row, $processor, $replace, $source): int {
                    $workspace = $access->resolveForWorker($principal, $workspaceId, $tenant, write: true, lock: true, operation: 'files.optimize');
                    $current = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $row->id)
                        ->whereNull('trashed_at')->where('source_kind', $source)->where('current_version_id', $row->current_version_id)->first();

                    return $current === null ? 0 : $processor->schedule($workspace, $current, $principal, replace: $replace);
                }, 3);
            }
        }

        $this->info(sprintf('Scanned %d entries, %d eligible, %d queued conversions. Next cursor: %d.', $rows->count(), $eligible, $queued, $rows->last()->id ?? $after));

        return self::SUCCESS;
    }
}
