<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Uploads;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneUploads extends Command
{
    protected $signature = 'media-studio:uploads:prune {--limit=100 : Maximum sessions per invocation}';

    protected $description = 'Remove staging parts for cancelled, completed or expired upload sessions in bounded batches.';

    public function handle(UploadSessions $uploads): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $ids = DB::table('fms_upload_sessions')->whereNull('cleaned_at')->where(function ($query): void {
            $query->where(function ($q): void {
                $q->whereIn('state', ['uploading', 'initializing'])->where('expires_at', '<=', now());
            })->orWhere(function ($q): void {
                $q->where('state', 'finalizing')->where('lease_expires_at', '<=', now())
                    ->where(fn ($query) => $query->where('transport', 'local')->orWhere('expires_at', '<=', now()));
            })->orWhereIn('state', ['completed', 'cancelled', 'expired']);
        })->orderBy('id')->limit($limit)->pluck('id');
        $cleaned = 0;
        foreach ($ids as $id) {
            try {
                $row = DB::transaction(function () use ($id): ?object {
                    $row = DB::table('fms_upload_sessions')->where('id', $id)->lockForUpdate()->first();
                    if ($row === null || $row->cleaned_at !== null) {
                        return null;
                    }
                    if ($row->state === 'finalizing' && (strtotime($row->lease_expires_at) > time()
                        || ($row->transport === S3Multipart::TRANSPORT && strtotime($row->expires_at) > time()))) {
                        return null;
                    }
                    if (in_array($row->state, ['uploading', 'initializing'], true) && strtotime($row->expires_at) > time()) {
                        return null;
                    }
                    if (in_array($row->state, ['uploading', 'initializing', 'finalizing'], true)) {
                        DB::table('fms_upload_sessions')->where('id', $id)->update([
                            'state' => 'expired', 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
                        ]);
                        $row->state = 'expired';
                    }

                    return $row;
                });
                if ($row !== null && $uploads->cleanup($row)) {
                    $cleaned++;
                }
            } catch (\Throwable $error) {
                report($error);
                $this->error('A session could not be cleaned; it will be retried.');
            }
        }
        $this->info('Cleaned '.$cleaned.' upload sessions.');

        return self::SUCCESS;
    }
}
