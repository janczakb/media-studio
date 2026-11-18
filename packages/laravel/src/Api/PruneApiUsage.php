<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Api;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneApiUsage extends Command
{
    protected $signature = 'media-studio:api:prune';

    protected $description = 'Remove a bounded batch of expired API statistics and token history.';

    public function handle(): int
    {
        $before = now('UTC')->subDays(90)->toDateString();
        $rows = DB::table('fms_api_usage')->where('day', '<', $before)->orderBy('day')->limit(1000)->get(['token_id', 'day', 'shard']);
        foreach ($rows as $row) {
            DB::table('fms_api_usage')->where('token_id', $row->token_id)->where('day', $row->day)->where('shard', $row->shard)->delete();
        }
        $ids = DB::table('fms_api_tokens')->where('expires_at', '<', $before)->orderBy('expires_at')->limit(500)->pluck('id');
        DB::table('fms_api_tokens')->whereIn('id', $ids)->delete();
        $this->info('API retention batch completed.');

        return self::SUCCESS;
    }
}
