<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Processing;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Renditions\Renditions;

final class ReconcileRenditions extends Command
{
    protected $signature = 'media-studio:renditions:reconcile {--limit=100 : Maximum candidate rendition rows per invocation} {--images : Execute pending image batches without a queue worker} {--documents : Execute document thumbnail batches without a queue worker} {--max-seconds=60 : Stop admitting work after this execution budget}';

    protected $description = 'Redispatch pending image conversions and expired processing leases in bounded batches.';

    public function handle(ThumbnailProcessor $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $budget = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT);
        if ($budget === false || $budget < 1 || $budget > 3600) {
            return self::INVALID;
        }
        if ($this->option('images') && $this->option('documents')) {
            $this->error('Choose --images or --documents.');

            return self::INVALID;
        }
        $deadline = microtime(true) + $budget;
        $cooldown = now()->subSeconds(max(30, (int) config('media-studio.processing.native.dispatch_retry_seconds', 600)));
        $rows = DB::table('fms_renditions as r')->join('fms_entries as e', 'e.id', '=', 'r.entry_id')
            ->join('fms_workspaces as w', 'w.id', '=', 'e.workspace_id')
            ->when($this->option('documents'), fn ($q) => $q->whereNotNull('r.recipe->document_generator'))
            ->where('r.owned', true)->whereNotNull('r.principal_type')->whereNotNull('r.principal_id')
            ->when($this->option('images'), fn ($q) => $q->where('e.media_type', 'image')->whereNull('r.recipe->document_generator'))
            ->where('r.attempts', '<', max(1, (int) config('media-studio.processing.native.max_attempts', 3)))
            ->when(! $this->option('images'), fn ($q) => $q->where('r.dispatch_attempts', '<', 12))
            ->where(fn ($q) => $q->whereIn('r.state', ['queued', 'failed'])->orWhere(fn ($q) => $q->where('r.state', 'processing')->where('r.lease_expires_at', '<=', now())))
            ->when(! $this->option('images'), fn ($q) => $q->where(fn ($q) => $q->whereNull('r.last_dispatched_at')->orWhere('r.last_dispatched_at', '<=', $cooldown)))
            ->whereColumn('r.version_id', 'e.current_version_id')->whereNull('e.trashed_at')->whereIn('e.source_kind', ['managed', 'disk'])
            ->orderBy('r.state')->orderBy('r.lease_expires_at')->orderBy('r.id')->limit($limit)
            ->get(['r.id', 'r.version_id', 'r.principal_type', 'r.principal_id', 'w.public_id as workspace',
                'w.tenant_type', 'w.tenant_id', 'w.tenant_key', 'r.recipe']);
        $visited = [];
        $dispatched = 0;
        $failed = 0;
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $recipe = json_decode($row->recipe ?? '{}', true);
            if ($this->option('documents') && (! isset($recipe['document_generator']) || ! config('media-studio.processing.document_thumbnails.enabled', false))) {
                continue;
            }
            if (isset($visited[$row->version_id])) {
                continue;
            }
            $visited[$row->version_id] = true;
            $principal = new Principal($row->principal_type, $row->principal_id);
            $tenant = TenantIdentity::fromWorkspace($row);
            try {
                app(WorkspaceAccess::class)->resolveForWorker($principal, $row->workspace, $tenant, write: true, operation: 'files.process');
                if ($this->option('documents') || $this->option('images')) {
                    $processor->execute($row->workspace, $row->version_id, $principal, $tenant);
                    $dispatched++;

                    continue;
                }
                $dispatched += $processor->dispatch($row->workspace, $row->version_id, $principal, $tenant) ? 1 : 0;
            } catch (HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }
                $blocked = DB::table('fms_renditions')->where('version_id', $row->version_id)->where('owned', true)
                    ->whereIn('state', ['queued', 'failed', 'processing'])->get(['id', 'entry_id', 'preset', 'object_key']);
                foreach ($blocked as $rendition) {
                    DB::table('fms_renditions')->where('id', $rendition->id)->update(['state' => 'blocked', 'failure_code' => 'permission_revoked', 'updated_at' => now()]);
                    if ($rendition->object_key === null) {
                        app(Renditions::class)->project($rendition->entry_id, $row->version_id, $rendition->preset, 'blocked');
                    }
                }
            } catch (\Throwable $error) {
                if (! $this->option('images')) {
                    throw $error;
                }
                report($error);
                $failed++;
            }
        }
        $this->info(($this->option('images') || $this->option('documents') ? 'Executed ' : 'Dispatched ').$dispatched.' conversion batches; '.$failed.' failed.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
