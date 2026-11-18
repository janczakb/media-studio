<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class LibraryTest extends TestCase
{
    public function test_installs_without_filament_and_preserves_string_principal_keys(): void
    {
        $this->assertFalse(class_exists('Filament\\FilamentServiceProvider'));
        $id = $this->workspace();
        $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->assertJsonPath('items.0.id', $id);
        $this->assertDatabaseHas('fms_memberships', ['principal_id' => 'member-uuid-001', 'role' => 'owner']);
    }

    public function test_access_is_not_granted_by_knowing_a_workspace_identifier(): void
    {
        $workspace = $this->workspace();
        $this->actingAs(new GenericUser(['id' => 'another-member']));
        $this->getJson($this->url($workspace))->assertNotFound();
        $this->postJson($this->url($workspace, 'folders'), ['name' => 'Private'])->assertNotFound();
        $this->getJson('/media-studio/api/v1/workspaces')->assertJsonCount(0, 'items');
    }

    public function test_viewer_cannot_write_and_unregistered_models_cannot_access(): void
    {
        $workspace = $this->workspace();
        DB::table('fms_memberships')->update(['role' => 'viewer']);
        $this->getJson($this->url($workspace))->assertOk()->assertJsonPath('canWrite', false);
        $this->postJson($this->url($workspace, 'folders'), ['name' => 'No'])->assertForbidden();
        config()->set('media-studio.principals', []);
        $this->getJson($this->url($workspace))->assertForbidden();
    }

    public function test_folder_parent_must_belong_to_the_selected_workspace(): void
    {
        $a = $this->workspace('AAA');
        $b = $this->workspace('BBB');
        $folder = $this->postJson($this->url($a, 'folders'), ['name' => 'Images'])->assertCreated()->json('id');
        $this->postJson($this->url($b, 'folders'), ['name' => 'Child', 'parent' => $folder])->assertNotFound();
        $this->postJson($this->url($a, 'folders'), ['name' => 'IMAGES'])->assertConflict();
        $this->postJson($this->url($a, 'folders'), ['name' => '../secret'])->assertUnprocessable();
    }

    public function test_folder_disclosures_only_report_active_child_folders_in_one_bounded_query(): void
    {
        $workspace = $this->workspace();
        $parent = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Parent'])->assertCreated()->json('id');
        $leaf = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Leaf'])->assertCreated()->json('id');
        $child = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Child', 'parent' => $parent])->assertCreated()->json('id');
        DB::enableQueryLog();
        $response = $this->getJson($this->url($workspace).'?view=folders')->assertOk();
        $childQueries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'as "parents"'));
        DB::disableQueryLog();
        $this->assertCount(1, $childQueries);
        $cards = collect($response->json('items'))->keyBy('id');
        $this->assertTrue($cards[$parent]['hasChildFolders']);
        $this->assertFalse($cards[$leaf]['hasChildFolders']);
        $operation = $this->postJson($this->url($workspace, 'entries/'.$child.'/trash'), ['revision' => 1])->assertStatus(202)->json('treeOperation.id');
        $this->postJson($this->url($workspace, 'tree-operations/'.$operation.'/advance'))->assertOk()->assertJsonPath('treeOperation.state', 'completed');
        $cards = collect($this->getJson($this->url($workspace).'?view=folders')->assertOk()->json('items'))->keyBy('id');
        $this->assertFalse($cards[$parent]['hasChildFolders']);
    }

    public function test_all_folders_are_reachable_through_bound_keyset_pagination(): void
    {
        $workspace = $this->workspace();
        foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $name) {
            $this->postJson($this->url($workspace, 'folders'), ['name' => $name])->assertCreated();
        }
        $seen = [];
        $cursor = null;
        do {
            $query = http_build_query(['limit' => 2, 'cursor' => $cursor]);
            $response = $this->getJson($this->url($workspace).'?'.$query)->assertOk();
            $seen = [...$seen, ...array_column($response->json('items'), 'name')];
            $cursor = $response->json('nextCursor');
        } while ($cursor !== null);

        $this->assertSame(['Five', 'Four', 'One', 'Three', 'Two'], $seen);
        $this->assertCount(5, array_unique($seen));
    }

    public function test_cursor_cannot_be_reused_in_a_different_scope_or_modified(): void
    {
        $workspace = $this->workspace();
        foreach (['A', 'B'] as $name) {
            $this->postJson($this->url($workspace, 'folders'), ['name' => $name])->assertCreated();
        }
        $cursor = $this->getJson($this->url($workspace).'?limit=1')->assertOk()->json('nextCursor');
        $this->getJson($this->url($workspace).'?'.http_build_query(['view' => 'photos', 'cursor' => $cursor]))->assertUnprocessable();
        $this->getJson($this->url($workspace).'?cursor=malformed')->assertUnprocessable();
        $this->getJson($this->url($workspace).'?limit=999')->assertUnprocessable();
    }

    public function test_upload_streams_private_bytes_and_retry_reuses_the_same_asset(): void
    {
        $workspace = $this->workspace();
        $file = UploadedFile::fake()->image('profile.png', 80, 80);
        $key = 'upload-key-00000000001';
        $first = $this->withHeader('Idempotency-Key', $key)->postJson($this->url($workspace, 'uploads'), ['file' => $file])->assertCreated();
        $again = $this->withHeader('Idempotency-Key', $key)->postJson($this->url($workspace, 'uploads'), ['file' => $file])->assertCreated();
        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertDatabaseCount('fms_entries', 1);
        $this->assertDatabaseCount('fms_asset_versions', 1);
        $version = DB::table('fms_asset_versions')->first();
        $this->assertSame('private', Storage::disk('studio-test')->getVisibility($version->object_key));
        $this->getJson($this->url($workspace).'?view=photos')->assertJsonPath('items.0.id', $first->json('id'));
        $response = $this->get($this->url($workspace, 'entries/'.$first->json('id').'/content'))->assertOk();
        $this->assertSame(file_get_contents($file->getPathname()), $response->streamedContent());
        $this->actingAs(new GenericUser(['id' => 'someone-else']));
        $this->getJson($this->url($workspace, 'entries/'.$first->json('id').'/content'))->assertNotFound();
    }

    public function test_retry_key_rejects_different_bytes_and_conflicts_leave_no_orphans(): void
    {
        $workspace = $this->workspace();
        $first = UploadedFile::fake()->createWithContent('brief.txt', 'First brief');
        $second = UploadedFile::fake()->createWithContent('brief.txt', 'Another brief');
        $this->withHeader('Idempotency-Key', 'upload-key-00000000001')->postJson($this->url($workspace, 'uploads'), ['file' => $first])->assertCreated();
        $this->withHeader('Idempotency-Key', 'upload-key-00000000001')->postJson($this->url($workspace, 'uploads'), ['file' => $second])->assertConflict();
        $this->withHeader('Idempotency-Key', 'upload-key-00000000002')->postJson($this->url($workspace, 'uploads'), ['file' => $second])->assertConflict();
        $this->assertCount(1, Storage::disk('studio-test')->allFiles());
        $this->assertDatabaseCount('fms_asset_versions', 1);
        $this->assertDatabaseHas('fms_upload_receipts', ['state' => 'failed']);
    }

    public function test_browse_query_count_is_bounded_without_counting_or_loading_entire_library(): void
    {
        $workspace = $this->workspace();
        $internalId = DB::table('fms_workspaces')->value('id');
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $name = sprintf('Folder %04d', $i);
            $rows[] = ['public_id' => (string) Str::ulid(), 'workspace_id' => $internalId, 'parent_scope' => 0, 'kind' => 'folder', 'sort_group' => 0, 'name' => $name, 'normalized_name' => strtolower($name), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('fms_entries')->insert($batch);
        }
        DB::enableQueryLog();
        $this->getJson($this->url($workspace))->assertOk()->assertJsonCount(60, 'items')->assertJsonPath('hasMore', true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(3, count($queries));
        $sql = strtolower(implode(' ', array_column($queries, 'query')));
        $this->assertStringNotContainsString('count(', $sql);
        $this->assertStringContainsString('limit 61', $sql);
    }
}
