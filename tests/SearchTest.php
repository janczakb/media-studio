<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SearchTest extends TestCase
{
    private function enable(): void
    {
        config(['media-studio.search.enabled' => true, 'media-studio.search.meilisearch.host' => 'http://search.test:7700', 'media-studio.search.meilisearch.key' => 'server-secret']);
        Http::preventStrayRequests();
    }

    private function entry(string $workspace, string $name): string
    {
        $id = (string) Str::ulid();
        DB::table('fms_entries')->insert(['workspace_id' => DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'), 'public_id' => $id, 'kind' => 'file', 'sort_group' => 1, 'name' => $name, 'normalized_name' => strtolower($name), 'source_kind' => 'managed', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_library_search_paginates_nested_files_and_binds_the_cursor_to_its_filters(): void
    {
        $workspace = $this->workspace();
        $other = $this->workspace('Other');
        $folder = $this->entry($workspace, 'Brand');
        $first = $this->entry($workspace, 'Photo A.jpg');
        $second = $this->entry($workspace, 'Photo B.jpg');
        $trashed = $this->entry($workspace, 'Photo 0.jpg');
        $this->entry($other, 'Photo private.jpg');
        DB::table('fms_entries')->where('public_id', $folder)->update(['kind' => 'folder']);
        DB::table('fms_entries')->where('public_id', $first)->update(['parent_id' => DB::table('fms_entries')->where('public_id', $folder)->value('id')]);
        DB::table('fms_entries')->where('public_id', $trashed)->update(['trashed_at' => now()]);
        Http::fake();

        $url = $this->url($workspace, 'library-search');
        $page = $this->getJson($url.'?q=photo&limit=1')->assertOk()->assertJsonPath('items.0.id', $first)
            ->assertJsonPath('items.0.parentName', 'Brand')->assertJsonPath('hasMore', true);
        $cursor = urlencode($page->json('nextCursor'));
        $this->getJson($url.'?q=photo&limit=1&cursor='.$cursor)->assertOk()->assertJsonPath('items.0.id', $second)->assertJsonPath('hasMore', false);
        $this->getJson($url.'?q=other&cursor='.$cursor)->assertUnprocessable();
        $this->getJson($url.'?kind=folder')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $folder);
        $this->actingAs(new GenericUser(['id' => 'outsider']))->getJson($url.'?q=photo')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_uploader_applies_in_search_and_browse_and_index_returns_authorized_cards(): void
    {
        $workspace = $this->workspace();
        $other = $this->workspace('Other');
        $first = $this->entry($workspace, 'Photo A.jpg');
        $second = $this->entry($workspace, 'Photo B.jpg');
        $foreign = $this->entry($other, 'Photo private.jpg');
        $member = DB::table('fms_memberships')->where('workspace_id', DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'))->first();
        DB::table('fms_entries')->where('public_id', $first)->update(['uploader_key' => $member->principal_key]);
        $filter = http_build_query(['filters' => ['uploaderIds' => [$member->id]]]);

        $url = $this->url($workspace, 'library-search');
        $this->getJson($url.'?q=photo&'.$filter)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $first);
        $this->getJson($this->url($workspace).'?'.$filter)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $first);

        $this->enable();
        Http::fake(['search.test:7700/*' => Http::response(['hits' => [
            ['id' => $second, 'revision' => 1, 'version' => null],
            ['id' => $foreign, 'revision' => 1, 'version' => null],
            ['id' => $first, 'revision' => 1, 'version' => null, 'name' => 'Untrusted'],
        ]])]);
        $this->getJson($url.'?q=photo&mode=index&'.$filter)->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Photo A.jpg')->assertJsonPath('items.0.kind', 'file');
    }

    public function test_disabled_search_makes_no_network_requests(): void
    {
        $workspace = $this->workspace();
        Http::fake();
        $this->getJson($this->url($workspace, 'search').'?q=cat')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_search_rechecks_workspace_trash_and_revision_and_never_exposes_index_names_or_counts(): void
    {
        $this->enable();
        $workspace = $this->workspace();
        $other = $this->workspace('Other');
        $id = $this->entry($workspace, 'Actual name');
        $foreign = $this->entry($other, 'Private');
        Http::fake(['search.test:7700/*' => Http::response(['hits' => [['id' => $id, 'revision' => 1, 'version' => null, 'name' => 'Untrusted'], ['id' => $foreign, 'revision' => 1, 'version' => null]], 'estimatedTotalHits' => 9999])]);
        $this->getJson($this->url($workspace, 'search').'?q=cat')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.name', 'Actual name')->assertJsonMissingPath('estimatedTotalHits');
        Http::assertSent(fn ($request) => $request['filter'] === 'workspace = "'.$workspace.'"' && $request->hasHeader('Authorization', 'Bearer server-secret'));
        DB::table('fms_entries')->where('public_id', $id)->update(['revision' => 2]);
        $this->getJson($this->url($workspace, 'search').'?q=cat')->assertOk()->assertJsonCount(0, 'items');
        DB::table('fms_entries')->where('public_id', $id)->update(['revision' => 1, 'trashed_at' => now()]);
        $this->getJson($this->url($workspace, 'search').'?q=cat')->assertOk()->assertJsonCount(0, 'items');
        Http::fake();
        $this->actingAs(new GenericUser(['id' => 'unauthorized']))->getJson($this->url($workspace, 'search').'?q=cat')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_service_failure_is_generic_and_does_not_fall_back_to_unrestricted_search(): void
    {
        $this->enable();
        $workspace = $this->workspace();
        Http::fake(['search.test:7700/*' => Http::response(['message' => 'secret internal exception'], 500)]);
        $this->getJson($this->url($workspace, 'search').'?q=cat')->assertStatus(503)->assertDontSee('secret internal');
    }

    public function test_index_submission_is_bounded_and_reports_task_not_completion(): void
    {
        $this->enable();
        $workspace = $this->workspace();
        $this->entry($workspace, 'First');
        $this->entry($workspace, 'Second');
        Http::fake(['search.test:7700/*' => Http::response(['taskUid' => 12], 202)]);
        $this->artisan('media-studio:search-index', ['--limit' => 1])->expectsOutput('Documents task: 12')->assertSuccessful();
        Http::assertSent(fn ($request) => count($request->data()) === 1 && $request->data()[0]['name'] === 'First');
        $this->artisan('media-studio:search-index', ['--limit' => 121])->assertFailed();
    }
}
