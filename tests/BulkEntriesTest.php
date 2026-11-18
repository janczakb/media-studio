<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Library\BulkEntries;

final class BulkEntriesTest extends TestCase
{
    private function file(string $workspace, string $name, ?string $parent = null): string
    {
        return $this->withHeader('Idempotency-Key', 'bulk-test-'.bin2hex(random_bytes(16)))
            ->postJson($this->url($workspace, 'uploads'), [
                'file' => UploadedFile::fake()->createWithContent($name, 'Original bytes for '.$name),
                'parent' => $parent,
            ])->assertCreated()->json('id');
    }

    private function folder(string $workspace, string $name, ?string $parent = null): string
    {
        return $this->postJson($this->url($workspace, 'folders'), ['name' => $name, 'parent' => $parent])
            ->assertCreated()->json('id');
    }

    private function items(array $ids, int $revision = 1): array
    {
        return array_map(fn (string $id): array => ['id' => $id, 'revision' => $revision], $ids);
    }

    public function test_moves_trashes_and_restores_in_input_order_without_rewriting_versions(): void
    {
        $workspace = $this->workspace();
        $first = $this->file($workspace, 'first.txt');
        $second = $this->file($workspace, 'second.txt');
        $folder = $this->folder($workspace, 'Destination');
        $url = $this->url($workspace, 'entries/bulk');
        $versions = DB::table('fms_asset_versions')->orderBy('id')->get()->all();

        $move = $this->postJson($url, ['operation' => 'move', 'parent' => $folder, 'items' => $this->items([$second, $first])])
            ->assertOk()->assertJsonPath('items.0.id', $second)->assertJsonPath('items.1.id', $first)
            ->assertJsonPath('items.0.revision', 2)->assertJsonPath('items.1.revision', 2);
        $this->assertCount(2, $move->json('items'));
        $this->getJson($this->url($workspace).'?parent='.$folder)->assertOk()->assertJsonCount(2, 'items');

        $this->postJson($url, ['operation' => 'trash', 'items' => $this->items([$first, $second], 2)])
            ->assertOk()->assertJsonPath('items.0.revision', 3);
        $this->getJson($this->url($workspace).'?parent='.$folder)->assertOk()->assertJsonCount(0, 'items');
        $this->postJson($url, ['operation' => 'restore', 'items' => $this->items([$first, $second], 3)])
            ->assertOk()->assertJsonPath('items.0.revision', 4)->assertJsonPath('items.1.trashedAt', null);
        $this->getJson($this->url($workspace).'?parent='.$folder)->assertOk()->assertJsonCount(2, 'items');
        $this->assertEquals($versions, DB::table('fms_asset_versions')->orderBy('id')->get()->all());
        $this->assertCount(2, Storage::disk('studio-test')->allFiles());
    }

    public function test_stale_or_foreign_entries_and_destinations_reject_the_entire_batch(): void
    {
        $workspace = $this->workspace();
        $other = $this->workspace('Other');
        $first = $this->file($workspace, 'first.txt');
        $second = $this->file($workspace, 'second.txt');
        $foreign = $this->file($other, 'foreign.txt');
        $foreignFolder = $this->folder($other, 'Private');
        $url = $this->url($workspace, 'entries/bulk');
        $this->patchJson($this->url($workspace, 'entries/'.$second), ['revision' => 1, 'name' => 'renamed.txt'])->assertOk();

        $this->postJson($url, ['operation' => 'trash', 'items' => $this->items([$first, $second])])->assertConflict();
        $this->postJson($url, ['operation' => 'trash', 'items' => $this->items([$first, $foreign])])->assertNotFound();
        $this->postJson($url, ['operation' => 'move', 'parent' => $foreignFolder, 'items' => $this->items([$first])])->assertNotFound();

        $this->assertDatabaseHas('fms_entries', ['public_id' => $first, 'revision' => 1, 'trashed_at' => null, 'parent_id' => null]);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $second, 'revision' => 2, 'trashed_at' => null]);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $foreign, 'revision' => 1, 'trashed_at' => null]);
    }

    public function test_batch_move_rejects_cycles_and_name_collisions_without_partial_changes(): void
    {
        $workspace = $this->workspace();
        $folder = $this->folder($workspace, 'Parent');
        $child = $this->folder($workspace, 'Child', $folder);
        $first = $this->file($workspace, 'first.txt');
        $conflict = $this->file($workspace, 'conflict.txt');
        $existing = $this->file($workspace, 'conflict.txt', $child);
        $url = $this->url($workspace, 'entries/bulk');

        $this->postJson($url, ['operation' => 'move', 'parent' => $child, 'items' => $this->items([$first, $folder])])->assertUnprocessable();
        $this->postJson($url, ['operation' => 'move', 'parent' => $child, 'items' => $this->items([$first, $conflict])])->assertConflict();

        foreach ([$first, $conflict, $folder] as $id) {
            $this->assertDatabaseHas('fms_entries', ['public_id' => $id, 'revision' => 1, 'parent_id' => null]);
        }

        $this->postJson($this->url($workspace, 'entries/'.$existing.'/trash'), ['revision' => 1])->assertOk();
        $this->postJson($url, ['operation' => 'move', 'parent' => $child, 'items' => $this->items([$first, $conflict])])->assertOk();
        $this->assertDatabaseHas('fms_entries', ['public_id' => $conflict, 'revision' => 2, 'trashed_at' => null]);
        $this->assertNotNull(DB::table('fms_entries')->where('public_id', $existing)->value('trashed_at'));
    }

    public function test_trash_admits_trees_and_rejects_existing_usages_and_restore_requires_available_parent(): void
    {
        $workspace = $this->workspace();
        $folder = $this->folder($workspace, 'Parent');
        $child = $this->file($workspace, 'child.txt', $folder);
        $unrelated = $this->file($workspace, 'unrelated.txt');
        $url = $this->url($workspace, 'entries/bulk');
        $operation = $this->postJson($url, ['operation' => 'trash', 'items' => $this->items([$unrelated, $folder, $child])])->assertStatus(202)->json('treeOperation.id');
        $this->postJson($this->url($workspace, 'tree-operations/'.$operation.'/cancel'))->assertOk();
        $entry = DB::table('fms_entries')->where('public_id', $child)->first();
        DB::table('fms_usages')->insert([
            'workspace_id' => $entry->workspace_id, 'entry_id' => $entry->id, 'version_id' => $entry->current_version_id,
            'owner_type' => 'post', 'owner_id' => 'post-1', 'owner_key' => hash('sha256', 'post-1'), 'attribute' => 'body', 'slot' => 'first',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson($url, ['operation' => 'trash', 'items' => $this->items([$unrelated, $child])])->assertConflict();
        $this->assertDatabaseCount('fms_usages', 1);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $unrelated, 'revision' => 1, 'trashed_at' => null]);
        DB::table('fms_entries')->whereIn('public_id', [$folder, $child, $unrelated])->update(['trashed_at' => now(), 'revision' => 2]);
        $this->postJson($url, ['operation' => 'restore', 'items' => $this->items([$unrelated, $child], 2)])->assertConflict();
        $this->assertDatabaseHas('fms_entries', ['public_id' => $unrelated, 'revision' => 2]);
        $this->assertNotNull(DB::table('fms_entries')->where('public_id', $unrelated)->value('trashed_at'));
    }

    public function test_batch_rejects_read_only_sources_and_rechecks_current_workspace_role(): void
    {
        $workspace = $this->workspace();
        $first = $this->file($workspace, 'first.txt');
        $source = $this->file($workspace, 'source.txt');
        $destination = $this->folder($workspace, 'Read-only destination');
        $url = $this->url($workspace, 'entries/bulk');
        DB::table('fms_entries')->whereIn('public_id', [$source, $destination])->update(['source_kind' => 'spatie']);

        $this->postJson($url, ['operation' => 'trash', 'items' => $this->items([$first, $source])])->assertForbidden();
        $this->postJson($url, ['operation' => 'move', 'parent' => $destination, 'items' => $this->items([$first])])->assertForbidden();
        DB::table('fms_memberships')->update(['role' => 'viewer']);
        $this->postJson($url, ['operation' => 'trash', 'items' => $this->items([$first])])->assertForbidden();

        $this->assertDatabaseHas('fms_entries', ['public_id' => $first, 'revision' => 1, 'trashed_at' => null, 'parent_id' => null]);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $source, 'revision' => 1, 'trashed_at' => null]);
    }

    public function test_maximum_batch_uses_one_update_and_rejects_invalid_batch_shapes(): void
    {
        $workspace = $this->workspace();
        $workspaceId = DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');
        $folder = $this->folder($workspace, 'Destination');
        $ids = [];
        $rows = [];
        for ($i = 0; $i < BulkEntries::MAX_ITEMS; $i++) {
            $ids[] = $id = (string) Str::ulid();
            $rows[] = [
                'public_id' => $id, 'workspace_id' => $workspaceId, 'kind' => 'file', 'sort_group' => 1,
                'name' => 'File '.$i, 'normalized_name' => 'file '.$i, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('fms_entries')->insert($rows);
        $url = $this->url($workspace, 'entries/bulk');
        $this->postJson($url, ['operation' => 'trash', 'items' => []])->assertUnprocessable();
        $this->postJson($url, ['operation' => 'trash', 'items' => $this->items([$ids[0], strtolower($ids[0])])])->assertUnprocessable();
        $this->postJson($url, ['operation' => 'trash', 'items' => $this->items([...$ids, (string) Str::ulid()])])->assertUnprocessable();
        $this->postJson($url, ['operation' => 'move', 'items' => $this->items([$ids[0]])])->assertUnprocessable();

        DB::enableQueryLog();
        try {
            $this->postJson($url, ['operation' => 'move', 'parent' => $folder, 'items' => $this->items($ids)])
                ->assertOk()->assertJsonCount(BulkEntries::MAX_ITEMS, 'items');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        // Six mutation queries plus four constant journal/revision queries, independent of batch size.
        $this->assertLessThanOrEqual(10, count($queries));
        $this->assertCount(1, array_filter($queries, fn (array $query): bool => str_starts_with($query['query'], 'update "fms_entries"')));
        $this->assertCount(1, array_filter($queries, fn (array $query): bool => str_starts_with($query['query'], 'update "fms_workspaces"')));
        $this->assertCount(1, array_filter($queries, fn (array $query): bool => str_starts_with($query['query'], 'insert into "fms_navigation_changes"')));
        $this->assertSame(BulkEntries::MAX_ITEMS, DB::table('fms_entries')->whereIn('public_id', $ids)->where('revision', 2)->count());
    }
}
