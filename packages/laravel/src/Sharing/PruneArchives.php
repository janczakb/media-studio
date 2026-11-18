<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sharing;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PruneArchives extends Command
{
    protected $signature = 'media-studio:prune-archives {--limit=100}';

    protected $description = 'Delete expired prepared archives in bounded batches.';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $rows = DB::table('fms_archive_jobs')->where('expires_at', '<=', now())->orderBy('expires_at')->limit($limit)->get();
        foreach ($rows as $row) {
            if ($row->object_key !== null) {
                if (! str_starts_with($row->object_key, 'flex-media-studio/archives/'.$row->id.'-')) {
                    $this->error('Invalid archive key; record preserved.');

                    continue;
                }
                if (! Storage::disk($row->disk)->delete($row->object_key)) {
                    $this->error('Unable to delete expired archive; record preserved.');

                    continue;
                }
            }
            DB::table('fms_archive_jobs')->where('id', $row->id)->where('expires_at', '<=', now())->delete();
        }

        return self::SUCCESS;
    }
}
