<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Navigation\NavigationChanges;

final class FolderNavigationTest extends TestCase
{
    private function chain(string $workspace, int $depth): array
    {
        $wid = DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');
        $rows = [];
        $parent = null;
        DB::transaction(function () use ($wid, $depth, &$parent, &$rows): void {
            for ($index = 0; $index < $depth; $index++) {
                $public = (string) Str::ulid();
                $id = DB::table('fms_entries')->insertGetId([
                    'public_id' => $public, 'workspace_id' => $wid, 'parent_id' => $parent, 'parent_scope' => $parent ?? 0,
                    'name' => 'Level '.$index, 'normalized_name' => 'level '.$index, 'kind' => 'folder', 'sort_group' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $rows[] = ['id' => $id, 'public' => $public];
                $parent = $id;
            }
        });

        return $rows;
    }

    public function test_context_at_ten_thousand_levels_has_bounded_payload_and_recursive_work(): void
    {
        $workspace = $this->workspace();
        $chain = $this->chain($workspace, 10000);
        DB::enableQueryLog();
        $response = $this->getJson($this->url($workspace, 'folders/'.$chain[9999]['public'].'/context'))->assertOk()
            ->assertJsonCount(4, 'tail')->assertJsonPath('tail.3.name', 'Level 9999')->assertJsonPath('hasCollapsedAncestors', true)
            ->assertJsonPath('totalDepth', null);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, array_filter($queries, fn ($query) => str_contains($query['query'], 'WITH RECURSIVE trail')));
        $this->assertLessThan(6000, strlen($response->getContent()));
        $this->assertLessThan(25, count($queries));
    }

    public function test_ancestor_pages_are_nearest_first_complete_and_scoped(): void
    {
        $workspace = $this->workspace();
        $chain = $this->chain($workspace, 160);
        $folder = $chain[159]['public'];
        $context = $this->getJson($this->url($workspace, 'folders/'.$folder.'/context'))->assertOk()->json();
        $cursor = $context['ancestorCursor'];
        $names = [];
        do {
            $page = $this->getJson($this->url($workspace, 'folders/'.$folder.'/ancestors').'?'.http_build_query(['cursor' => $cursor, 'limit' => 50]))->assertOk()->json();
            $this->assertLessThanOrEqual(50, count($page['items']));
            array_push($names, ...array_column($page['items'], 'name'));
            $cursor = $page['nextCursor'];
        } while ($cursor !== null);
        $this->assertSame(array_map(fn ($n) => 'Level '.$n, range(155, 0)), $names);
        $cursor = $context['ancestorCursor'];
        $this->getJson($this->url($workspace, 'folders/'.$chain[158]['public'].'/ancestors').'?'.http_build_query(['cursor' => $cursor]))->assertUnprocessable();
        $this->actingAs(new GenericUser(['id' => 'intruder']));
        $this->getJson($this->url($workspace, 'folders/'.$folder.'/context'))->assertNotFound();
    }

    public function test_committed_folder_changes_invalidate_ancestor_cursor_and_journal_rolls_back(): void
    {
        $workspace = $this->workspace();
        $chain = $this->chain($workspace, 8);
        $folder = $chain[7]['public'];
        $context = $this->getJson($this->url($workspace, 'folders/'.$folder.'/context'))->assertOk()->json();
        $this->patchJson($this->url($workspace, 'entries/'.$chain[2]['public']), ['revision' => 1, 'name' => 'Renamed'])->assertOk();
        $this->getJson($this->url($workspace, 'folders/'.$folder.'/ancestors').'?'.http_build_query(['cursor' => $context['ancestorCursor']]))->assertConflict();
        $this->assertDatabaseCount('fms_navigation_changes', 1);
        DB::beginTransaction();
        app(NavigationChanges::class)->record((int) DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'), 'created', []);
        DB::rollBack();
        $this->assertDatabaseCount('fms_navigation_changes', 1);
    }

    public function test_move_verifies_deep_destination_in_batches_and_rejects_cycles(): void
    {
        $workspace = $this->workspace();
        $chain = $this->chain($workspace, 160);
        $leaf = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Other'])->assertCreated()->json('id');
        DB::enableQueryLog();
        $this->patchJson($this->url($workspace, 'entries/'.$leaf), ['revision' => 1, 'parent' => $chain[159]['public']])->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, array_filter($queries, fn ($query) => str_contains($query['query'], 'WITH RECURSIVE trail')));
        $this->patchJson($this->url($workspace, 'entries/'.$chain[0]['public']), ['revision' => 1, 'parent' => $leaf])->assertUnprocessable();
        $this->assertDatabaseHas('fms_entries', ['id' => $chain[0]['id'], 'parent_id' => null]);
    }

    public function test_unauthorized_ancestor_names_and_identities_do_not_escape_the_context(): void
    {
        $workspace = $this->workspace();
        $chain = $this->chain($workspace, 4);
        DB::table('fms_entries')->where('id', $chain[1]['id'])->update(['source_kind' => 'disk', 'name' => 'Secret ancestor']);
        $response = $this->getJson($this->url($workspace, 'folders/'.$chain[3]['public'].'/context'))->assertOk()->assertJsonCount(2, 'tail')->assertJsonPath('ancestorCursor', null);
        $this->assertStringNotContainsString('Secret ancestor', $response->getContent());
        $this->assertStringNotContainsString($chain[1]['public'], $response->getContent());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_import_segment_budget_does_not_impose_a_global_depth_limit(): void
    {
        $workspace = $this->workspace();
        $path = implode('/', array_map(fn ($i) => 'Folder '.$i, range(1, 60)));
        $result = $this->postJson($this->url($workspace, 'folders/resolve'), ['parent' => null, 'paths' => [$path]])->assertOk();
        $folder = $result->json('items.0.id');
        $this->getJson($this->url($workspace, 'folders/'.$folder.'/context'))->assertOk()->assertJsonPath('tail.3.name', 'Folder 60');
    }

    public function test_incomplete_or_corrupt_ancestry_never_passes_verification(): void
    {
        $workspace = $this->workspace();
        $chain = $this->chain($workspace, 160);
        config()->set('media-studio.navigation.verification_nodes', 100);
        $leaf = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Other'])->assertCreated()->json('id');
        $this->patchJson($this->url($workspace, 'entries/'.$leaf), ['revision' => 1, 'parent' => $chain[159]['public']])->assertConflict();
        $this->assertDatabaseHas('fms_entries', ['public_id' => $leaf, 'parent_id' => null, 'revision' => 1]);
        config()->set('media-studio.navigation.verification_nodes', 20000);
        DB::table('fms_entries')->where('id', $chain[0]['id'])->update(['parent_id' => $chain[159]['id']]);
        $this->patchJson($this->url($workspace, 'entries/'.$leaf), ['revision' => 1, 'parent' => $chain[159]['public']])->assertUnprocessable();
    }
}
