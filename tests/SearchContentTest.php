<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaCore\Text\TextExtractor;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Search\ContentAdmission;
use UseFlex\MediaStudio\Search\ExtractContentJob;
use UseFlex\MediaStudio\Search\ExtractTextContent;
use UseFlex\MediaStudio\Search\SearchSynchronizer;

final class SearchContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../packages/laravel/database/search-migrations/*.php') as $path) {
            (require $path)->up();
        }
        config(['media-studio.search.enabled' => true, 'media-studio.search.content.enabled' => true, 'media-studio.search.meilisearch.host' => 'http://search.test:7700', 'media-studio.search.meilisearch.key' => 'test-key']);
        Http::preventStrayRequests();
    }

    private function fixture(string $text = 'Żółty kot przy oknie'): array
    {
        $workspace = $this->workspace();
        $id = (string) Str::ulid();
        $version = (string) Str::ulid();
        $entry = DB::table('fms_entries')->insertGetId(['workspace_id' => DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'), 'public_id' => $id, 'kind' => 'file', 'sort_group' => 1, 'name' => 'Example.txt', 'normalized_name' => 'example.txt', 'source_kind' => 'managed', 'revision' => 1, 'current_version_id' => $version, 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('studio-test')->put('text/'.$version, $text);
        DB::table('fms_asset_versions')->insert(['id' => $version, 'entry_id' => $entry, 'disk' => 'studio-test', 'object_key' => 'text/'.$version, 'sha256' => hash('sha256', $text), 'size_bytes' => strlen($text), 'mime_type' => 'text/plain', 'created_at' => now()]);

        return [$workspace, $id, $entry, $version];
    }

    public function test_automatic_content_admission_is_async_bounded_and_idempotent(): void
    {
        [$workspace, $id, $entryId, $version] = $this->fixture();
        $scope = DB::table('fms_workspaces')->where('public_id', $workspace)->first();
        $entry = DB::table('fms_entries')->where('id', $entryId)->first();
        $entry->size_bytes = 100;
        $actor = new Principal('user', 'member-uuid-001');
        config(['media-studio.search.content.automatic' => true]);
        Bus::fake();
        app(ContentAdmission::class)->schedule($scope, $entry, $actor);
        $this->assertSame(0, DB::table('fms_content_jobs')->count());
        Bus::assertNothingDispatched();
        config(['queue.connections.content' => ['driver' => 'database'], 'media-studio.search.content.connection' => 'content']);
        app(ContentAdmission::class)->schedule($scope, $entry, $actor);
        app(ContentAdmission::class)->schedule($scope, $entry, $actor);
        Bus::assertDispatchedTimes(ExtractContentJob::class, 1);
        config(['media-studio.search.content.max_pending_per_workspace' => 1]);
        $entry->current_version_id = (string) Str::ulid();
        app(ContentAdmission::class)->schedule($scope, $entry, $actor);
        $this->assertSame(1, DB::table('fms_content_jobs')->count());
        $job = new ExtractContentJob($workspace, $id, $version, $actor->type, $actor->id, null, null);
        $job->handle(app(ExtractTextContent::class));
        $this->assertSame('ready', DB::table('fms_content_jobs')->where('version_id', $version)->value('state'));
        $this->assertSame('Żółty kot przy oknie', DB::table('fms_search_content')->value('content'));
        Http::assertNothingSent();
    }

    public function test_content_worker_does_not_publish_an_obsolete_or_revoked_version(): void
    {
        [$workspace, $id, $entry, $version] = $this->fixture();
        DB::table('fms_content_jobs')->insert(['entry_id' => $entry, 'version_id' => $version, 'state' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        $job = new ExtractContentJob($workspace, $id, $version, 'user', 'member-uuid-001', null, null);
        Gate::define('studio.files.process', fn () => false);
        $job->handle(app(ExtractTextContent::class));
        $this->assertSame('failed', DB::table('fms_content_jobs')->value('state'));
        $this->assertSame(0, DB::table('fms_search_content')->count());
        DB::table('fms_content_jobs')->update(['state' => 'queued']);
        DB::table('fms_entries')->where('id', $entry)->update(['current_version_id' => (string) Str::ulid()]);
        $job->handle(app(ExtractTextContent::class));
        $this->assertSame('obsolete', DB::table('fms_content_jobs')->value('state'));
        Http::assertNothingSent();
    }

    public function test_markdown_mime_aliases_and_tsv_are_supported_without_binary_extension_fallback(): void
    {
        $extract = app(TextExtractor::class);
        $this->assertSame('text/markdown', $extract->mime('text/x-markdown; charset=utf-8', 'NOTES.MD'));
        $this->assertSame('text/plain', $extract->mime('text/plain', 'notes.md'));
        $this->assertSame('text/csv', $extract->mime('text/tab-separated-values', 'table.tsv'));
        $this->assertSame('text/html', $extract->mime('text/plain', 'page.html'));
        $this->assertNull($extract->mime('application/octet-stream', 'notes.md'));
        $this->assertNull($extract->mime('application/rtf', 'document.rtf'));
    }

    public function test_skip_policy_never_opens_storage(): void
    {
        [$workspace, $id] = $this->fixture('abcdef');
        config(['media-studio.search.content.large_threshold_bytes' => 3]);
        $extract = app(ExtractTextContent::class);
        Storage::shouldReceive('disk')->never();
        $this->assertSame('skipped_size', $extract->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null));
        $this->assertSame('', DB::table('fms_search_content')->value('content'));
    }

    public function test_bounded_policy_allows_soft_threshold_but_never_exceeds_hard_limit(): void
    {
        [$workspace, $id] = $this->fixture('abcdef');
        config(['media-studio.search.content.large_threshold_bytes' => 3, 'media-studio.search.content.oversize_policy' => 'bounded']);
        $extract = app(ExtractTextContent::class);
        $actor = new Principal('user', 'member-uuid-001');
        $this->assertSame('ready', $extract->run($actor, $workspace, $id, null));
        config(['media-studio.search.content.max_source_bytes' => 4]);
        $this->assertSame('skipped_size', $extract->run($actor, $workspace, $id, null));
        $this->assertSame('', DB::table('fms_search_content')->value('content'));
    }

    public function test_html_extraction_removes_active_content_preserves_plain_text_and_reports_truncation(): void
    {
        $extract = app(TextExtractor::class);
        $result = $extract->extract('<style>secret</style><script>fetch("evil")</script><p>Żółty &amp; biały</p><p>kot</p>', 'text/html', 100);
        $this->assertSame('Żółty & biały kot', $result['text']);
        $this->assertFalse($result['partial']);
        $this->assertSame(['text' => 'abc', 'partial' => true], $extract->extract('abcdef', 'text/plain', 3));
        $this->assertSame('<b>literal</b>', $extract->extract('<b>literal</b>', 'text/plain', 100)['text']);
    }

    public function test_extract_publish_search_and_disable_have_separate_fingerprints(): void
    {
        [$workspace, $id, $entry, $version] = $this->fixture();
        $this->assertSame('ready', app(ExtractTextContent::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null));
        $content = DB::table('fms_search_content')->where('entry_id', $entry)->first();
        $this->assertSame('Żółty kot przy oknie', $content->content);
        Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/search') ? ['hits' => [['id' => $id, 'revision' => 1, 'version' => $version, 'contentHash' => $content->fingerprint]]] : ['taskUid' => 10]));
        app(SearchSynchronizer::class)->run();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/documents?') && ($request->data()[0]['content'] ?? '') === 'Żółty kot przy oknie');
        $this->getJson($this->url($workspace, 'search').'?q=kot')->assertOk()->assertJsonCount(1, 'items')->assertJsonMissingPath('items.0.content');
        config(['media-studio.search.content.enabled' => false]);
        $this->getJson($this->url($workspace, 'search').'?q=kot')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_partial_text_is_explicit_and_processing_permission_is_required(): void
    {
        [$workspace, $id] = $this->fixture('abcdef');
        config(['media-studio.search.content.max_characters' => 3]);
        $this->assertSame('partial', app(ExtractTextContent::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null));
        $this->assertSame('abc', DB::table('fms_search_content')->value('content'));
        Gate::define('studio.files.process', fn () => false);
        $this->expectException(HttpException::class);
        app(ExtractTextContent::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null);
    }

    public function test_oversized_input_is_not_opened_and_changed_bytes_never_publish(): void
    {
        [$workspace, $id, $entry, $version] = $this->fixture();
        config(['media-studio.search.content.max_source_bytes' => 2, 'media-studio.search.content.large_threshold_bytes' => 2]);
        $this->assertSame('skipped_size', app(ExtractTextContent::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null));
        $this->assertSame('skipped_size', DB::table('fms_search_content')->value('state'));
        DB::table('fms_search_content')->delete();
        config(['media-studio.search.content.max_source_bytes' => 1024, 'media-studio.search.content.large_threshold_bytes' => 1024]);
        Storage::disk('studio-test')->put('text/'.$version, 'changed');
        try {
            app(ExtractTextContent::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null);
            $this->fail('Changed original must not publish.');
        } catch (\RuntimeException) {
            $this->assertSame(0, DB::table('fms_search_content')->count());
        }
    }
}
