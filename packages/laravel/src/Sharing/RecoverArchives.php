<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sharing;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class RecoverArchives extends Command
{
    protected $signature = 'media-studio:recover-archives {--limit=100}';

    protected $description = 'Retry queued or interrupted archive preparation in bounded batches.';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $cutoff = now()->subMinutes(70);
        $rows = DB::table('fms_archive_jobs')->where('expires_at', '>', now())
            ->where(function ($q) use ($cutoff): void {
                $q->where(fn ($q) => $q->where('state', 'queued')->where('updated_at', '<', now()->subMinutes(5)))
                    ->orWhere(fn ($q) => $q->where('state', 'processing')->where('updated_at', '<', $cutoff));
            })->orderBy('updated_at')->limit($limit)->get();
        foreach ($rows as $row) {
            $claimed = DB::table('fms_archive_jobs')->where('id', $row->id)->where('state', $row->state)->where('updated_at', $row->updated_at)
                ->update(['state' => 'queued', 'lease_token' => null, 'updated_at' => now()]);
            if ($claimed !== 1) {
                continue;
            }
            if ($row->object_key !== null) {
                if (! str_starts_with($row->object_key, 'flex-media-studio/archives/'.$row->id.'-') || ! Storage::disk($row->disk)->delete($row->object_key)) {
                    DB::table('fms_archive_jobs')->where('id', $row->id)->where('state', 'queued')->update(['state' => 'failed', 'updated_at' => now()]);

                    continue;
                }
            }
            $job = new PrepareArchive($row->id);
            $job->onConnection(config('media-studio.downloads.connection'))->onQueue(config('media-studio.downloads.queue'));
            app(Dispatcher::class)->dispatch($job);
        }

        return self::SUCCESS;
    }
}
