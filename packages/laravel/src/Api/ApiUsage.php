<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Api;

use Illuminate\Support\Facades\DB;

final class ApiUsage
{
    public function admit(object $token): array
    {
        $day = now('UTC')->toDateString();
        $shard = random_int(0, 15);
        DB::table('fms_api_usage')->upsert([
            ['token_id' => $token->id, 'day' => $day, 'shard' => $shard, 'requests' => 1],
        ], ['token_id', 'day', 'shard'], ['requests' => DB::raw('fms_api_usage.requests + 1')]);
        if ($token->last_used_at === null || strtotime($token->last_used_at) < time() - 60) {
            DB::table('fms_api_tokens')->where('id', $token->id)->update(['last_used_at' => now()]);
        }

        return ['day' => $day, 'shard' => $shard];
    }

    public function finish(object $token, array $bucket, int $status, int $duration): void
    {
        $column = $status >= 500 ? 'server_errors' : ($status >= 400 ? 'client_errors' : 'successes');
        DB::table('fms_api_usage')->where('token_id', $token->id)->where('day', $bucket['day'])->where('shard', $bucket['shard'])->update([
            $column => DB::raw($column.' + 1'), 'duration_ms' => DB::raw('duration_ms + '.max(0, $duration)),
        ]);
    }
}
