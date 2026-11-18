<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Uploads;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PruneUploadAttempts extends Command
{
    protected $signature = 'media-studio:uploads:prune-attempts {--limit=100 : Maximum upload attempts per invocation}';

    protected $description = 'Retry cleanup of abandoned ordinary upload writes in bounded batches.';

    public function handle(UploadAttempts $attempts): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $ids = DB::table('fms_upload_attempts')->where('next_cleanup_at', '<=', now())
            ->orderBy('next_cleanup_at')->orderBy('id')->limit($limit)->pluck('id');
        $cleaned = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                if ($attempts->cleanup($id)) {
                    $cleaned++;
                }
            } catch (Throwable $error) {
                report($error);
                $failed++;
            }
        }
        $this->info('Cleaned '.$cleaned.' upload attempts.');
        if ($failed > 0) {
            $this->error($failed.' attempts could not be cleaned and remain scheduled for retry.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
