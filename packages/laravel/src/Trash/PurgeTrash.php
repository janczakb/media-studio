<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Trash;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use UseFlex\MediaStudio\Identity\Principal;

final class PurgeTrash extends Command
{
    protected $signature = 'media-studio:trash:prune {workspace} {principal-type} {principal-id}
        {--after= : Resume after the timestamp/ID cursor} {--limit=100 : Inspect at most 100 candidates}
        {--retry= : Resume one unfinished purge by operation ULID}
        {--apply : Admit durable deletion jobs; otherwise inspect only}';

    protected $description = 'Inspect expired managed trash, or queue bounded, reference-aware deletion.';

    public function handle(TrashRetention $retention): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $after = $this->option('after');
        $retry = $this->option('retry');
        $workspaceId = (string) $this->argument('workspace');
        if (! Str::isUlid($workspaceId) || $limit === false
            || ($after !== null && ! preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\/([1-9][0-9]{0,17})\z/', $after, $position))
            || ($retry !== null && (! Str::isUlid($retry) || ! $this->option('apply')))) {
            $this->error('Provide a workspace ULID, limit 1–100, and optional timestamp/ID cursor. Retrying a purge requires its ULID and --apply.');

            return self::INVALID;
        }
        $principal = new Principal((string) $this->argument('principal-type'), (string) $this->argument('principal-id'));
        $workspace = $retention->workspace($principal, $workspaceId);
        if ($this->option('apply') && ! config('media-studio.trash.retention_enabled', false)) {
            $this->error('Enable trash retention explicitly before applying deletion.');

            return self::FAILURE;
        }
        if ($retry !== null) {
            $retention->resume($principal, $workspaceId, $retry);
            $retention->dispatch($retry);
            $this->info('The purge was submitted to the queue.');

            return self::SUCCESS;
        }
        $queued = $errors = 0;
        if ($this->option('apply')) {
            // A command rerun also recovers committed admissions whose queue dispatch was interrupted.
            $pending = DB::table('fms_trash_purges')->where('workspace_id', $workspace->id)->whereIn('state', ['queued', 'failed', 'processing'])
                ->where('principal_type', $principal->type)->where('principal_id', $principal->id)->where('attempts', '<', $retention->maxAttempts())
                ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subMinutes(5)))
                ->orderBy('id')->limit($limit)->pluck('id');
            foreach ($pending as $id) {
                try {
                    $queued += (int) $retention->dispatch($id);
                } catch (Throwable $error) {
                    report($error);
                    $errors++;
                }
            }
        }
        $remaining = max(0, $limit - $queued - $errors);
        $rows = $remaining === 0 ? collect() : $retention->candidates($workspace, $remaining, isset($position[1]) ? str_replace('T', ' ', $position[1]) : null, (int) ($position[2] ?? 0))
            ->get(['id', 'public_id', 'trashed_at']);
        foreach ($rows as $row) {
            if (! $this->option('apply')) {
                continue;
            }
            try {
                $queued += (int) $retention->dispatch($retention->schedule($principal, $workspaceId, $row->public_id));
            } catch (Throwable $error) {
                report($error);
                $errors++;
            }
        }
        $last = $rows->last();
        $next = $last === null ? $after : str_replace(' ', 'T', $last->trashed_at).'/'.$last->id;
        $this->info(sprintf('Inspected %d new candidates. Queued %d. Skipped or failed %d.', $rows->count(), $queued, $errors));
        if ($next !== null) {
            $this->line('Next cursor: '.$next);
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
