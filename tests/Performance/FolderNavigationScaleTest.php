<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Performance;

use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Tests\TestCase;

/** Opt-in real SQL dataset; no object files, services or demo data are created. */
final class FolderNavigationScaleTest extends TestCase
{
    public function test_bounded_folder_pages_against_a_million_asset_database(): void
    {
        if (getenv('FMS_LARGE_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set FMS_LARGE_BENCHMARK=1 for the isolated large SQL fixture.');
        }
        $workspace = $this->workspace();
        $wid = DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');
        $started = hrtime(true);
        $stamp = now()->toDateTimeString();
        DB::transaction(function () use ($wid, $stamp): void {
            for ($batch = 0; $batch < 2200; $batch++) {
                $rows = [];
                for ($offset = 1; $offset <= 500; $offset++) {
                    $id = $batch * 500 + $offset;
                    $folder = $id <= 100000;
                    $parent = $folder ? null : (($id - 100001) % 1000) + 1;
                    $name = ($folder ? 'Folder ' : 'Asset ').sprintf('%08d', $id).($folder ? '' : '.pdf');
                    $rows[] = ['public_id' => sprintf('%026d', $id), 'workspace_id' => $wid, 'parent_id' => $parent,
                        'parent_scope' => $parent ?? 0, 'kind' => $folder ? 'folder' : 'file', 'sort_group' => $folder ? 0 : 1,
                        'name' => $name, 'normalized_name' => strtolower($name), 'created_at' => $stamp, 'updated_at' => $stamp];
                }
                DB::table('fms_entries')->insert($rows);
                if (($batch + 1) % 200 === 0) {
                    fwrite(STDERR, 'Scale fixture: '.(($batch + 1) * 500).' SQL entries'.PHP_EOL);
                }
            }
        });
        $seedMs = (hrtime(true) - $started) / 1000000;
        $samples = [];
        $cursor = null;
        $seen = [];
        $firstQuery = null;
        for ($page = 0; $page < 30; $page++) {
            $url = $this->url($workspace).'?'.http_build_query(['view' => 'folders', 'limit' => 50, 'cursor' => $cursor]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            $response = $this->getJson($url)->assertOk()->assertJsonCount(50, 'items');
            $samples[] = (hrtime(true) - $start) / 1000000;
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertLessThan(25, count($queries));
            foreach ($queries as $query) {
                if ($firstQuery === null && str_contains($query['query'], 'from "fms_entries"') && str_contains($query['query'], 'order by')) {
                    $firstQuery = $query;
                }
            }
            foreach ($response->json('items') as $item) {
                $this->assertArrayNotHasKey($item['id'], $seen);
                $seen[$item['id']] = true;
            }
            $this->assertLessThan(70000, strlen($response->getContent()));
            $cursor = $response->json('nextCursor');
        }
        $plan = DB::select('EXPLAIN QUERY PLAN '.$firstQuery['query'], $firstQuery['bindings']);
        $planText = implode(' | ', array_column($plan, 'detail'));
        $this->assertStringContainsString('INDEX', $planText);
        $this->assertStringNotContainsString('TEMP B-TREE', $planText);
        $sorted = $samples;
        sort($sorted);
        $report = ['date' => gmdate('c'), 'php' => PHP_VERSION, 'platform' => PHP_OS_FAMILY,
            'sqlite' => DB::selectOne('select sqlite_version() as version')->version, 'database' => 'isolated in-memory SQLite',
            'entries' => 1100000, 'root_folders' => 100000, 'files' => 1000000, 'concurrency' => 1,
            'seed_ms' => $seedMs, 'first_request_ms' => $samples[0], 'request_ms' => $samples,
            'p50_ms' => $sorted[14], 'p95_ms' => $sorted[28], 'php_heap_peak_bytes' => memory_get_peak_usage(true),
            'query_plan' => $planText, 'limits' => 'In-process HTTP kernel, warm in-memory SQL; no external network, storage, production concurrency or native RSS measurement.'];
        fwrite(STDERR, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        if ($path = getenv('FMS_BENCHMARK_REPORT')) {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }
}
