<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Search\AiProjection;
use UseFlex\MediaStudio\Search\Meilisearch;

final class SemanticSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../packages/laravel/database/intelligence-migrations/*.php') as $path) {
            (require $path)->up();
        }
        config(['media-studio.intelligence.enabled' => true, 'media-studio.search.enabled' => true, 'media-studio.search.meilisearch.host' => 'http://search.test:7700', 'media-studio.search.meilisearch.key' => 'server-key']);
        Http::preventStrayRequests();
    }

    public function test_semantic_is_explicit_and_uses_scoped_engine_retrieval_with_fresh_label_hash(): void
    {
        $workspace = $this->workspace();
        $id = (string) Str::ulid();
        $version = (string) Str::ulid();
        $analysis = hash('sha256', $id);
        $entry = DB::table('fms_entries')->insertGetId(['workspace_id' => DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'), 'public_id' => $id, 'kind' => 'file', 'sort_group' => 1, 'name' => 'Cat', 'normalized_name' => 'cat', 'source_kind' => 'managed', 'current_version_id' => $version, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fms_image_analyses')->insert(['id' => $analysis, 'entry_id' => $entry, 'version_id' => $version, 'provider' => 'google_vision', 'description' => 'White cat', 'created_at' => now()]);
        DB::table('fms_ai_labels')->insert(['analysis_id' => $analysis, 'name' => 'White cat', 'normalized_name' => 'white cat', 'score' => .9]);
        $row = DB::table('fms_entries')->where('id', $entry)->first();
        $hash = app(AiProjection::class)->forEntries([$row])[$entry]['hash'];
        $this->getJson($this->url($workspace, 'search').'?q='.rawurlencode('biały kot').'&mode=semantic')->assertNotFound();
        $library = $this->url($workspace, 'library-search').'?q='.rawurlencode('biały kot');
        $this->getJson($library.'&mode=semantic')->assertNotFound();
        Http::assertNothingSent();
        config(['media-studio.search.semantic.enabled' => true]);
        Http::fake(['search.test:7700/*' => Http::response(['hits' => [['id' => $id, 'revision' => 1, 'version' => $version, 'aiHash' => $hash]]])]);
        $this->getJson($this->url($workspace, 'search').'?q='.rawurlencode('biały kot').'&mode=semantic')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson($library.'&mode=semantic&kind=file')->assertOk()
            ->assertJsonPath('items.0.id', $id)->assertJsonPath('items.0.kind', 'file');
        $this->getJson($library.'&mode=semantic&kind=folder')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($library.'&mode=hybrid')->assertOk()->assertJsonCount(1, 'items');
        Http::assertSent(fn ($request) => (float) $request['hybrid']['semanticRatio'] === 0.5);
        Http::assertSent(fn ($request) => $request['hybrid']['embedder'] === 'studio' && (float) $request['hybrid']['semanticRatio'] === 1.0 && $request['filter'] === 'workspace = "'.$workspace.'"');
        DB::table('fms_ai_labels')->where('analysis_id', $analysis)->update(['review_state' => 'rejected']);
        $this->getJson($this->url($workspace, 'search').'?q='.rawurlencode('biały kot').'&mode=semantic')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($library.'&mode=semantic')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_index_configuration_uses_a_server_side_embedder_without_exposing_credentials(): void
    {
        config(['media-studio.search.semantic.enabled' => true, 'media-studio.search.semantic.embedder' => ['source' => 'huggingFace', 'model' => 'intfloat/multilingual-e5-small']]);
        Http::fake(['search.test:7700/*' => Http::response(['taskUid' => 12], 202)]);
        $this->assertSame(12, app(Meilisearch::class)->configure());
        Http::assertSent(fn ($request) => $request['embedders']['studio']['source'] === 'huggingFace' && in_array('ai', $request['searchableAttributes'], true));
    }
}
