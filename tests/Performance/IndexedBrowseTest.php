<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Performance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Tests\TestCase;

final class IndexedBrowseTest extends TestCase
{
    public function test_large_library_uses_indexed_bounded_pages(): void
    {
        if (! getenv('STUDIO_LARGE_BENCHMARK')) {
            $this->markTestSkipped('Enable STUDIO_LARGE_BENCHMARK=1 for the 100,000-row benchmark.');
        }
        $count = (int) (getenv('STUDIO_BENCHMARK_ROWS') ?: 100000);
        $this->assertContains($count, [100000, 500000, 1000000]);
        $workspace = $this->workspace();
        $workspaceId = DB::table('fms_workspaces')->value('id');
        $created = now();
        $start = hrtime(true);
        for ($batch = 0; $batch < $count / 500; $batch++) {
            $rows = [];
            for ($i = 0; $i < 500; $i++) {
                $name = sprintf('Asset %06d.jpg', $batch * 500 + $i);
                $rows[] = ['public_id' => (string) Str::ulid(), 'workspace_id' => $workspaceId, 'parent_scope' => 0, 'kind' => 'file', 'sort_group' => 1, 'name' => $name, 'normalized_name' => strtolower($name), 'media_type' => 'image', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024000, 'created_at' => $created, 'updated_at' => $created];
            }
            DB::table('fms_entries')->insert($rows);
        }
        $seedMs = (hrtime(true) - $start) / 1000000;
        DB::statement('ANALYZE');
        $plan = DB::select('EXPLAIN QUERY PLAN SELECT public_id FROM fms_entries WHERE workspace_id = ? AND trashed_at IS NULL AND parent_scope = 0 ORDER BY sort_group, normalized_name, id LIMIT 61', [$workspaceId]);
        $planText = implode(' ', array_map(fn ($row): string => $row->detail, $plan));
        $this->assertStringContainsString('fms_active_browse', $planText);
        $timings = [];
        $cursor = null;
        $peakQueries = 0;
        for ($page = 0; $page < 30; $page++) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            $response = $this->getJson($this->url($workspace).'?'.http_build_query(['cursor' => $cursor]))->assertOk()->assertJsonCount(60, 'items');
            $timings[] = (hrtime(true) - $start) / 1000000;
            $cursor = $response->json('nextCursor');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $peakQueries = max($peakQueries, count($queries));
            $sql = strtolower(implode(' ', array_column($queries, 'query')));
            $this->assertStringNotContainsString('offset', $sql);
            $this->assertStringNotContainsString('count(', $sql);
            $this->assertLessThanOrEqual(3, count($queries));
        }
        sort($timings);
        $report = ['dataset' => number_format($count).' indexed file records, one workspace/root folder; SQLite :memory:; real route/auth/DTO, no real originals or S3', 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'seedMs' => round($seedMs, 2), 'requests' => 30, 'warmRouteP50Ms' => round($timings[14], 2), 'warmRouteP95Ms' => round($timings[28], 2), 'firstMeasuredRouteIncluded' => true, 'maxQueries' => $peakQueries, 'peakPhpMiB' => round(memory_get_peak_usage(true) / 1048576, 2), 'queryPlan' => $planText];
        fwrite(STDOUT, "\n".json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->assertNotNull($cursor);

        foreach (['updated', 'size', 'type'] as $sort) {
            $plan = DB::select('EXPLAIN QUERY PLAN SELECT public_id FROM fms_entries WHERE workspace_id = ? AND trashed_at IS NULL AND parent_scope = 0 ORDER BY browse_'.$sort.' DESC, id DESC LIMIT 61', [$workspaceId]);
            $detail = implode(' ', array_column($plan, 'detail'));
            $this->assertStringContainsString('fms_browse_'.$sort, $detail);
            $this->assertStringNotContainsString('TEMP B-TREE', $detail);
            $timings = [];
            $cursor = null;
            for ($page = 0; $page < 10; $page++) {
                $start = hrtime(true);
                $response = $this->getJson($this->url($workspace).'?'.http_build_query(['sort' => $sort, 'direction' => 'desc', 'cursor' => $cursor]))
                    ->assertOk()->assertJsonCount(60, 'items');
                $timings[] = (hrtime(true) - $start) / 1000000;
                $cursor = $response->json('nextCursor');
            }
            sort($timings);
            fwrite(STDOUT, json_encode(['sort' => $sort, 'rows' => $count, 'requests' => 10, 'p50Ms' => round($timings[4], 2), 'p95Ms' => round($timings[9], 2), 'queryPlan' => $detail], JSON_UNESCAPED_SLASHES)."\n");
        }

        foreach (['types' => ['image'], 'extensions' => ['jpg']] as $filter => $values) {
            $column = $filter === 'types' ? 'category' : 'extension';
            $value = $values[0];
            $plan = DB::select('EXPLAIN QUERY PLAN SELECT public_id FROM fms_entries WHERE workspace_id=? AND parent_scope=0 AND trashed_at IS NULL AND browse_'.$column.'=? ORDER BY sort_group,normalized_name,id LIMIT 61', [$workspaceId, $value]);
            $detail = implode(' ', array_column($plan, 'detail'));
            $this->assertStringContainsString('fms_browse_'.$column, $detail);
            $this->assertStringNotContainsString('TEMP B-TREE', $detail);
            $timings = [];
            $cursor = null;
            for ($page = 0; $page < 10; $page++) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $start = hrtime(true);
                $response = $this->getJson($this->url($workspace).'?'.http_build_query(['filters' => [$filter => $values], 'cursor' => $cursor]))
                    ->assertOk()->assertJsonCount(60, 'items');
                $timings[] = (hrtime(true) - $start) / 1000000;
                $cursor = $response->json('nextCursor');
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $this->assertLessThanOrEqual(3, count($queries));
                $this->assertStringNotContainsString('count(', strtolower(implode(' ', array_column($queries, 'query'))));
            }
            sort($timings);
            fwrite(STDOUT, json_encode(['filter' => $filter, 'rows' => $count, 'requests' => 10, 'p50Ms' => round($timings[4], 2), 'p95Ms' => round($timings[9], 2), 'queryPlan' => $detail], JSON_UNESCAPED_SLASHES)."\n");
        }

    }
}
