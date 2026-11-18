<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Processing;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class PruneRetiredRenditions extends Command
{
    protected $signature = 'media-studio:renditions:prune {workspace} {--after=0 : Start after this retirement ID}
        {--limit=100 : Maximum eligible objects to inspect} {--apply : Delete expired retired renditions}';

    protected $description = 'Inspect or delete replaced Studio conversions that have passed their retention period.';

    public function handle(): int
    {
        $workspaceId = (string) $this->argument('workspace');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (! Str::isUlid($workspaceId) || $limit === false || $after === false) {
            $this->error('Provide a valid workspace, limit (1–1000), and non-negative cursor.');

            return self::INVALID;
        }

        $workspace = DB::table('fms_workspaces')->where('public_id', $workspaceId)->first();
        if ($workspace === null) {
            $this->error('Workspace unavailable.');

            return self::FAILURE;
        }

        $rows = DB::table('fms_retired_renditions')->where('workspace_id', $workspace->id)
            ->where('delete_after', '<=', now())->where('id', '>', $after)->orderBy('id')->limit($limit)->get();
        $removed = $skipped = $errors = 0;
        $prefix = trim($workspace->storage_root, '/').'/'.$workspace->public_id.'/renditions/';
        foreach ($rows as $row) {
            if (! $this->option('apply')) {
                continue;
            }

            $key = $row->object_key;
            $owned = str_starts_with($key, $prefix) && preg_match(
                '/\A([0-9A-HJKMNP-TV-Z]{26})\/[a-f0-9]{32}\/[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/',
                substr($key, strlen($prefix)), $matches,
            );
            // Only immutable keys allocated by the native worker can enter this queue.
            if (! $owned || DB::table('fms_renditions')->where('disk', $row->disk)->where('object_key', $key)->exists()
                || DB::table('fms_asset_versions')->where('disk', $row->disk)->where('object_key', $key)->exists()) {
                $skipped++;

                continue;
            }
            try {
                $disk = Storage::disk($row->disk);
                if (! $disk->delete($key) && $disk->exists($key)) {
                    $errors++;

                    continue;
                }
                $removed += DB::table('fms_retired_renditions')->where('id', $row->id)->delete();
            } catch (Throwable $error) {
                report($error);
                $errors++;
            }
        }

        $this->info(sprintf('Inspected %d, removed %d, skipped %d, failed %d. Next cursor: %d.',
            $rows->count(), $removed, $skipped, $errors, $rows->last()->id ?? $after));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
