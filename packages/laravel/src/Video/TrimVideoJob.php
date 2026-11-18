<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Video;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class TrimVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public string $id)
    {
        $this->timeout = (int) config('media-studio.video_editing.timeout', 60) + 60;
    }

    public function handle(VideoEdits $edits): void
    {
        $edits->run($this->id);
    }

    public function failed(?\Throwable $error): void
    {
        DB::table('fms_video_edits')->where('id', $this->id)->whereIn('state', ['queued', 'processing'])->update(['state' => 'failed', 'error' => 'worker_failed', 'finished_at' => now(), 'updated_at' => now()]);
    }
}
