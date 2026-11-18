<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Performance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Tests\TestCase;

final class ApiUsageTest extends TestCase
{
    public function test_bounded_usage_rollup_with_full_history(): void
    {
        if (! getenv('FMS_API_BENCHMARK')) {
            self::markTestSkipped('Opt-in isolated 48,000-row API usage benchmark.');
        }
        $w = $this->workspace();
        $workspace = DB::table('fms_workspaces')->where('public_id', $w)->value('id');
        $principal = new Principal('user', 'member-uuid-001');
        for ($i = 0; $i < 100; $i++) {
            $token = DB::table('fms_api_tokens')->insertGetId(['public_id' => (string) Str::ulid(),
                'workspace_id' => $workspace, 'principal_key' => $principal->key(), 'principal_type' => 'user', 'principal_id' => $principal->id,
                'name' => 'History '.$i, 'secret_hash' => str_repeat('0', 64), 'suffix' => '0000', 'can_write' => false,
                'created_at' => now(), 'expires_at' => now()->addDay(), 'revoked_at' => $i < 80 ? now() : null]);
            $rows = [];
            for ($day = 0; $day < 30; $day++) {
                for ($shard = 0; $shard < 16; $shard++) {
                    $rows[] = ['token_id' => $token, 'day' => now('UTC')->subDays($day)->toDateString(), 'shard' => $shard, 'requests' => 1000, 'successes' => 990, 'client_errors' => 10];
                }
            }
            DB::table('fms_api_usage')->insert($rows);
        }
        config(['media-studio.api.usage_cache_seconds' => 10]);
        $start = hrtime(true);
        $response = $this->getJson($this->url($w, 'api-access'))->assertOk()->assertJsonCount(30, 'usage')->assertJsonCount(100, 'items')->assertJsonPath('usage.29.requests', 1600000);
        $coldMs = (hrtime(true) - $start) / 1000000;
        $warm = [];
        for ($i = 0; $i < 20; $i++) {
            $start = hrtime(true);
            $this->getJson($this->url($w, 'api-access'))->assertOk()->assertJsonPath('usage.29.requests', 1600000);
            $warm[] = (hrtime(true) - $start) / 1000000;
        }
        sort($warm);
        file_put_contents(__DIR__.'/../../.internal/api-usage-benchmark.json', json_encode([
            'environment' => 'Local PHP '.PHP_VERSION.', Testbench, SQLite :memory:, array cache, sequential HTTP kernel requests; no real network or concurrency',
            'tokens' => 100, 'rollupRows' => 48000, 'representedRequests' => 48000000, 'representedRequestsAreSynthetic' => true,
            'coldMs' => $coldMs, 'warmMedianMs' => $warm[10], 'warmP95Ms' => $warm[18], 'responseBytes' => strlen($response->getContent()),
        ], JSON_PRETTY_PRINT));
    }
}
