<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Search\SearchSynchronizer;

final class SearchSynchronizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../packages/laravel/database/search-migrations/2026_11_08_000001_search_sync.php')->up();
        config(['media-studio.search.enabled' => true, 'media-studio.search.meilisearch.host' => 'http://search.test:7700', 'media-studio.search.meilisearch.key' => 'test-key']);
        Http::preventStrayRequests();
    }

    private function fixture(): string
    {
        $workspace = $this->workspace();
        $id = (string) Str::ulid();
        DB::table('fms_entries')->insert(['workspace_id' => DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'), 'public_id' => $id, 'kind' => 'file', 'sort_group' => 1, 'name' => 'Original', 'normalized_name' => 'original', 'source_kind' => 'managed', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_only_successful_tasks_advance_receipts_and_unchanged_cycles_make_no_write(): void
    {
        $this->fixture();
        $status = 'enqueued';
        Http::fake(function ($request) use (&$status) {
            return Http::response(str_contains($request->url(), '/tasks/') ? ['status' => $status] : ['taskUid' => 10]);
        });
        $sync = app(SearchSynchronizer::class);
        $this->assertSame('submitted', $sync->run()['state']);
        $this->assertSame(0, DB::table('fms_search_receipts')->count());
        $this->assertSame('waiting', $sync->run()['state']);
        $status = 'succeeded';
        $this->assertSame('acknowledged', $sync->run()['state']);
        $this->assertSame(1, DB::table('fms_search_receipts')->count());
        $this->assertSame('sweeping', $sync->run()['state']);
        $this->assertSame('unchanged', $sync->run()['state']);
        $this->assertSame('complete', $sync->run()['state']);
        $count = count(Http::recorded());
        $this->assertSame('unchanged', $sync->run()['state']);
        $this->assertSame($count, count(Http::recorded()));
    }

    public function test_updates_during_pending_task_are_reconciled_and_hard_deletion_removes_document(): void
    {
        $id = $this->fixture();
        Http::fake(fn ($request) => Http::response(str_contains($request->url(), '/tasks/') ? ['status' => 'succeeded'] : ['taskUid' => 10]));
        $sync = app(SearchSynchronizer::class);
        $sync->run();
        DB::table('fms_entries')->where('public_id', $id)->update(['name' => 'Updated', 'revision' => 2]);
        $sync->run(); // acknowledges old fingerprint, never pretends new revision was indexed
        $sync->run(); // sweep
        $sync->run(); // known entry still exists
        $sync->run(); // cycle complete
        $this->assertSame('submitted', $sync->run()['state']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/documents?') && ($request->data()[0]['name'] ?? null) === 'Updated');
        $sync->run();
        DB::table('fms_entries')->where('public_id', $id)->delete();
        $sync->run();
        $this->assertSame('submitted', $sync->run()['state']);
        $this->assertSame(1, DB::table('fms_search_receipts')->count());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/delete-batch') && $request->data() === [$id]);
        $sync->run();
        $this->assertSame(0, DB::table('fms_search_receipts')->count());
    }

    public function test_failed_task_retries_without_advancing_checkpoint_and_http_failure_retains_pending(): void
    {
        $this->fixture();
        $sync = app(SearchSynchronizer::class);
        $response = ['taskUid' => 10];
        $code = 200;
        Http::fake(function () use (&$response, &$code) {
            return Http::response($response, $code);
        });
        $sync->run();
        $response = ['status' => 'failed'];
        $this->assertSame('failed', $sync->run()['state']);
        $this->assertSame(0, (int) DB::table('fms_search_syncs')->value('after_id'));
        $this->assertSame(0, DB::table('fms_search_receipts')->count());
        $response = ['taskUid' => 11];
        $this->assertSame('submitted', $sync->run()['state']);
        $response = [];
        $code = 503;
        try {
            $sync->run();
            $this->fail('Service failure must propagate.');
        } catch (\RuntimeException) {
            $this->assertSame(11, (int) DB::table('fms_search_syncs')->value('task_id'));
        }
    }
}
