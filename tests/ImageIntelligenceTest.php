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
use UseFlex\MediaCore\Intelligence\AnalysisResult;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Intelligence\AnalysisAdmission;
use UseFlex\MediaStudio\Intelligence\AnalysisImage;
use UseFlex\MediaStudio\Intelligence\AnalysisProfile;
use UseFlex\MediaStudio\Intelligence\AnalysisSource;
use UseFlex\MediaStudio\Intelligence\AnalyzeImage;
use UseFlex\MediaStudio\Intelligence\AnalyzeImageJob;
use UseFlex\MediaStudio\Intelligence\GoogleVisionAnalyzer;
use UseFlex\MediaStudio\Intelligence\MoondreamAnalyzer;
use UseFlex\MediaStudio\Renditions\ExternalVariant;
use UseFlex\MediaStudio\Renditions\ExternalVariantProvider;

final class ImageIntelligenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../packages/laravel/database/intelligence-migrations/2026_11_08_000001_image_analysis.php')->up();
        Http::preventStrayRequests();
    }

    private function profile(): void
    {
        config(['media-studio.intelligence.enabled' => true, 'media-studio.intelligence.images' => true, 'media-studio.intelligence.provider' => 'google_vision', 'media-studio.intelligence.providers.google_vision.key' => 'test-key']);
    }

    private function fixture(): array
    {
        $workspace = $this->workspace();
        $id = (string) Str::ulid();
        $version = (string) Str::ulid();
        $entry = DB::table('fms_entries')->insertGetId(['workspace_id' => DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'), 'public_id' => $id, 'kind' => 'file', 'sort_group' => 1, 'name' => 'Photo', 'normalized_name' => 'photo', 'media_type' => 'image', 'source_kind' => 'managed', 'current_version_id' => $version, 'created_at' => now(), 'updated_at' => now()]);
        $image = imagecreatetruecolor(32, 16);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        Storage::disk('studio-test')->put('analysis.png', $png);
        DB::table('fms_asset_versions')->insert(['id' => $version, 'entry_id' => $entry, 'disk' => 'studio-test', 'object_key' => 'analysis.png', 'sha256' => hash('sha256', $png), 'size_bytes' => strlen($png), 'mime_type' => 'image/png', 'created_at' => now()]);
        DB::table('fms_renditions')->insert(['entry_id' => $entry, 'version_id' => $version, 'preset' => 'ai-analysis-v1', 'state' => 'ready', 'owned' => true, 'disk' => 'studio-test', 'object_key' => 'analysis.png', 'mime_type' => 'image/png', 'size_bytes' => strlen($png), 'sha256' => hash('sha256', $png)]);

        return [$workspace, $id, $entry];
    }

    public function test_image_details_separate_manual_tags_from_authorized_current_analysis_and_ocr(): void
    {
        foreach ([
            'intelligence-migrations/2026_11_08_000003_label_reviews.php',
            'search-migrations/2026_11_08_000002_search_content.php',
        ] as $migration) {
            (require __DIR__.'/../packages/laravel/database/'.$migration)->up();
        }

        [$workspace, $publicId, $entryId] = $this->fixture();
        $url = '/media-studio/api/v1/workspaces/'.$workspace.'/entries/'.$publicId;
        $version = DB::table('fms_entries')->where('id', $entryId)->value('current_version_id');
        $revision = DB::table('fms_entries')->where('id', $entryId)->value('revision');
        $manual = $this->patchJson($url.'/tags', ['revision' => $revision, 'tags' => [['name' => 'Portfolio']]])
            ->assertOk()->assertJsonPath('tags.0.label', 'Portfolio')->json();
        $analysis = str_repeat('a', 64);
        DB::table('fms_image_analyses')->insert([
            'id' => $analysis, 'entry_id' => $entryId, 'version_id' => $version,
            'provider' => 'studio_demo', 'description' => 'Sample photograph', 'created_at' => now(),
        ]);
        DB::table('fms_ai_labels')->insert([
            ['analysis_id' => $analysis, 'name' => 'Nature', 'normalized_name' => 'nature', 'review_state' => 'pending'],
            ['analysis_id' => $analysis, 'name' => 'Rejected', 'normalized_name' => 'rejected', 'review_state' => 'rejected'],
        ]);
        DB::table('fms_search_content')->insert([
            'entry_id' => $entryId, 'version_id' => $version, 'fingerprint' => str_repeat('b', 64),
            'state' => 'partial', 'content' => 'Visible text', 'updated_at' => now(),
        ]);

        $this->getJson($url)->assertOk()->assertJsonPath('tags.0.label', 'Portfolio')
            ->assertJsonPath('canEditTags', true)->assertJsonMissingPath('imageInsights');

        $this->profile();
        config([
            'media-studio.search.content.enabled' => true,
            'media-studio.search.content.ocr.enabled' => true,
            'media-studio.search.content.ocr.images' => true,
        ]);
        $this->getJson($url)->assertOk()->assertJsonPath('imageInsights.analysis.sample', true)
            ->assertJsonPath('imageInsights.analysis.tags', ['Nature'])
            ->assertJsonPath('imageInsights.ocr', ['text' => 'Visible text', 'partial' => true]);

        $this->patchJson($url.'/tags', ['revision' => $manual['revision'], 'tags' => []])
            ->assertOk()->assertJsonPath('tags', []);
        Gate::define('studio.ai.view', static fn () => false);
        Gate::define('studio.tags.manage', static fn () => false);
        $this->getJson($url)->assertOk()->assertJsonPath('canEditTags', false)->assertJsonMissingPath('imageInsights');
        $this->patchJson($url.'/tags', ['revision' => $manual['revision'] + 1, 'tags' => [['name' => 'Denied']]])->assertForbidden();

        Gate::define('studio.ai.view', static fn () => true);
        DB::table('fms_entries')->where('id', $entryId)->update(['current_version_id' => (string) Str::ulid()]);
        $this->getJson($url)->assertOk()->assertJsonMissingPath('imageInsights');
    }

    public function test_ephemeral_analysis_image_uses_real_renderer_and_preserves_source(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD required.');
        }
        config(['media-studio.processing.native.driver' => 'gd']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        // Generate a valid source through GD rather than relying on a thumbnail placeholder.
        $image = imagecreatetruecolor(32, 16);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        Storage::disk('studio-test')->put('source.png', $png);
        $bytes = app(AnalysisImage::class)->prepare((object) ['disk' => 'studio-test', 'object_key' => 'source.png', 'size_bytes' => strlen($png), 'mime_type' => 'image/png', 'sha256' => hash('sha256', $png)]);
        $this->assertSame('image/jpeg', getimagesizefromstring($bytes)['mime']);
        $this->assertSame($png, Storage::disk('studio-test')->get('source.png'));
        $this->assertSame(['source.png'], Storage::disk('studio-test')->allFiles());
    }

    public function test_automatic_admission_requires_async_queue_and_respects_pending_limit(): void
    {
        (require __DIR__.'/../packages/laravel/database/intelligence-migrations/2026_11_08_000002_analysis_jobs.php')->up();
        $this->profile();
        config(['media-studio.intelligence.automatic' => true]);
        [$workspace, $id, $entryId] = $this->fixture();
        $w = DB::table('fms_workspaces')->where('public_id', $workspace)->first();
        $entry = DB::table('fms_entries')->where('id', $entryId)->first();
        $entry->size_bytes = 100;
        $admission = app(AnalysisAdmission::class);
        $actor = new Principal('user', 'member-uuid-001');
        $admission->schedule($w, $entry, $actor);
        $this->assertSame(0, DB::table('fms_analysis_jobs')->count());
        config(['queue.connections.analysis' => ['driver' => 'database'], 'media-studio.intelligence.connection' => 'analysis']);
        Bus::fake();
        $admission->schedule($w, $entry, $actor);
        $admission->schedule($w, $entry, $actor);
        $this->assertSame(1, DB::table('fms_analysis_jobs')->count());
        Bus::assertDispatchedTimes(AnalyzeImageJob::class, 1);
        config(['media-studio.intelligence.max_pending_per_workspace' => 1]);
        $entry->current_version_id = (string) Str::ulid();
        $admission->schedule($w, $entry, $actor);
        $this->assertSame(1, DB::table('fms_analysis_jobs')->count());
    }

    public function test_input_missing_policy_and_original_selection_are_explicit(): void
    {
        $this->profile();
        [$workspace, $id, $entryId] = $this->fixture();
        $entry = DB::table('fms_entries')->where('id', $entryId)->first();
        $w = DB::table('fms_workspaces')->where('public_id', $workspace)->first();
        $version = DB::table('fms_asset_versions')->where('id', $entry->current_version_id)->first();
        $actor = new Principal('user', 'member-uuid-001');
        config(['media-studio.intelligence.input' => ['type' => 'rendition', 'preset' => 'missing', 'missing' => 'wait']]);
        $this->assertNull(app(AnalysisSource::class)->resolve($w, $entry, $version, $actor));
        $this->assertSame('waiting_input', app(AnalyzeImage::class)->run($actor, $workspace, $id, null));
        config(['media-studio.intelligence.input.missing' => 'skip']);
        $this->assertSame('skipped_input', app(AnalyzeImage::class)->run($actor, $workspace, $id, null));
        config(['media-studio.intelligence.input.type' => 'original']);
        $this->assertSame($version->object_key, app(AnalysisSource::class)->resolve($w, $entry, $version, $actor)->object_key);
        config(['media-studio.processing.external_providers.test' => AnalysisVariantFixture::class, 'media-studio.intelligence.input' => ['type' => 'provider', 'provider' => 'test', 'preset' => 'custom', 'missing' => 'skip']]);
        $this->assertSame('external.png', app(AnalysisSource::class)->resolve($w, $entry, $version, $actor)->object_key);
        Http::assertNothingSent();
    }

    public function test_disabled_analysis_never_calls_provider(): void
    {
        Http::fake();
        try {
            app(AnalysisProfile::class)->image();
            $this->fail('Disabled analysis must fail closed.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_analysis_is_separate_from_user_tags_and_completed_results_are_reused(): void
    {
        $this->profile();
        [$workspace, $id] = $this->fixture();
        Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['labelAnnotations' => [['description' => 'Cat', 'score' => .92]]]]])]);
        $actor = new Principal('user', 'member-uuid-001');
        $this->assertSame('ready', app(AnalyzeImage::class)->run($actor, $workspace, $id, null));
        $this->assertSame('ready', app(AnalyzeImage::class)->run($actor, $workspace, $id, null));
        Http::assertSentCount(1);
        $this->assertSame('Cat', DB::table('fms_ai_labels')->value('name'));
        $this->assertSame(0, DB::table('fms_tags')->count());
        $this->assertSame(0, DB::table('fms_entry_tags')->count());
    }

    public function test_version_change_during_provider_call_cannot_publish_result(): void
    {
        $this->profile();
        [$workspace, $id, $entry] = $this->fixture();
        Http::fake(function () use ($entry) {
            DB::table('fms_entries')->where('id', $entry)->update(['current_version_id' => (string) Str::ulid()]);

            return Http::response(['responses' => [['labelAnnotations' => [['description' => 'Cat', 'score' => .92]]]]]);
        });
        try {
            app(AnalyzeImage::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null);
            $this->fail('Stale result must not publish.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame(0, DB::table('fms_ai_labels')->count());
    }

    public function test_revoked_processing_permission_during_call_blocks_publication(): void
    {
        $this->profile();
        [$workspace, $id] = $this->fixture();
        Http::fake(function () {
            Gate::define('studio.files.process', fn () => false);

            return Http::response(['responses' => [['labelAnnotations' => [['description' => 'Cat', 'score' => .92]]]]]);
        });
        try {
            app(AnalyzeImage::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null);
            $this->fail('Revoked permission must block publication.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame(0, DB::table('fms_image_analyses')->count());
    }

    public function test_moondream_keeps_confidence_unknown_and_rejects_malformed_output(): void
    {
        Http::fake(['api.moondream.ai/*' => Http::response(['answer' => json_encode(['description' => 'A cat.', 'labels' => ['Cat', 'cat']])])]);
        $result = app(MoondreamAnalyzer::class)->analyze('bytes', 'image/png', ['key' => 'test', 'model' => 'test-model', 'max_labels' => 24]);
        $this->assertCount(1, $result->labels);
        $this->assertNull($result->labels[0]['score']);
        $this->expectException(\UnexpectedValueException::class);
        new AnalysisResult([['name' => 'Cat', 'score' => 500]]);
    }

    public function test_provider_embedded_error_is_not_saved_as_an_empty_success(): void
    {
        Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['error' => ['code' => 429]]]])]);
        $this->expectException(\UnexpectedValueException::class);
        app(GoogleVisionAnalyzer::class)->analyze('bytes', 'image/png', ['key' => 'test', 'max_labels' => 24]);
    }
}

final class AnalysisVariantFixture implements ExternalVariantProvider
{
    public function variants(object $workspace, object $entry, object $version, Principal $principal): iterable
    {
        return [new ExternalVariant('custom', 'studio-test', 'external.png', 'image/png', 100, 32, 16, str_repeat('a', 64))];
    }
}
