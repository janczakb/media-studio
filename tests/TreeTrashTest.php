<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TreeTrashTest extends TestCase
{
    private function folder(string $workspace, string $name, ?string $parent = null): string
    {
        return $this->postJson($this->url($workspace, 'folders'), compact('name', 'parent'))->assertCreated()->json('id');
    }

    private function start(string $workspace, string $folder, string $kind = 'trash', int $revision = 1, ?string $key = null): string
    {
        return $this->postJson($this->url($workspace, 'entries/'.$folder.'/'.$kind), ['revision' => $revision, 'requestId' => $key ?? (string) Str::uuid()])->assertStatus(202)->json('treeOperation.id');
    }

    private function finish(string $workspace, string $id): array
    {
        for ($i = 0; $i < 100; $i++) {
            $result = $this->postJson($this->url($workspace, 'tree-operations/'.$id.'/advance'))->assertOk()->json();
            if ($result['treeOperation']['state'] !== 'preparing') {
                return $result;
            }
        }
        $this->fail('The bounded operation did not finish.');
    }

    public function test_reusing_a_trashed_folder_name_blocks_conflicting_restore_without_leaving_the_workspace_locked(): void
    {
        $workspace = $this->workspace();
        $root = $this->folder($workspace, 'Project');
        $child = $this->folder($workspace, 'Drafts', $root);
        $trash = $this->start($workspace, $root);
        $this->assertSame('completed', $this->finish($workspace, $trash)['treeOperation']['state']);

        $replacement = $this->postJson($this->url($workspace, 'folders/resolve'), ['paths' => ['Project']])
            ->assertOk()->json('items.0.id');
        $this->assertNotEmpty($replacement);
        $this->assertNotSame($root, $replacement);
        $restore = $this->start($workspace, $root, 'restore', 2);
        $result = $this->finish($workspace, $restore)['treeOperation'];
        $this->assertSame('failed', $result['state']);
        $this->assertSame('An item with this name already exists in the destination folder.', $result['error']);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $root, 'trash_group_id' => $trash]);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $child, 'trash_group_id' => $trash]);
        $this->folder($workspace, 'Deliverables', $replacement);
    }

    public function test_tree_trash_removes_related_links_atomically_and_restore_never_revives_them(): void
    {
        $workspace = $this->workspace();
        $root = $this->folder($workspace, 'Root');
        $child = $this->folder($workspace, 'Child', $root);
        $previous = $this->folder($workspace, 'Previously trashed', $child);
        $old = $this->start($workspace, $previous);
        $this->assertSame('completed', $this->finish($workspace, $old)['treeOperation']['state']);
        $share = $this->postJson($this->url($workspace, 'shares'), ['title' => 'Child link', 'entryIds' => [$child]])->assertCreated()->json();
        $key = (string) Str::uuid();
        $id = $this->start($workspace, $root, key: $key);
        $this->assertSame($id, $this->start($workspace, $root, key: $key));
        $this->assertDatabaseHas('fms_shares', ['public_id' => $share['id']]);
        $this->postJson($this->url($workspace, 'folders'), ['name' => 'Concurrent write'])->assertStatus(423);
        $this->assertSame('completed', $this->finish($workspace, $id)['treeOperation']['state']);
        $this->assertDatabaseMissing('fms_shares', ['public_id' => $share['id']]);
        $this->getJson($share['url'])->assertNotFound();
        $restore = $this->start($workspace, $root, 'restore', 2);
        $this->assertSame('completed', $this->finish($workspace, $restore)['treeOperation']['state']);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $child, 'trashed_at' => null]);
        $this->assertNotNull(DB::table('fms_entries')->where('public_id', $previous)->value('trashed_at'));
        $this->getJson($share['url'])->assertNotFound();
    }

    public function test_previously_deleted_file_is_grouped_under_deleted_folder_without_duplicate_trash_rows(): void
    {
        $workspace = $this->workspace();
        $root = $this->folder($workspace, 'Folder A');
        $nested = $this->folder($workspace, 'Nested', $root);
        $file = $this->withHeader('Idempotency-Key', 'trash-grouping-file-fixture')
            ->postJson($this->url($workspace, 'uploads'), ['parent' => $nested, 'file' => UploadedFile::fake()->createWithContent('File B.txt', 'Content')])->assertCreated()->json('id');
        $this->postJson($this->url($workspace, 'entries/'.$file.'/trash'), ['revision' => 1])->assertOk();
        $deletedAt = DB::table('fms_entries')->where('public_id', $file)->value('trashed_at');
        $report = $this->url($workspace, 'trash-report');
        $this->getJson($report)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $file);
        $this->travel(1)->days();
        $operation = $this->start($workspace, $root);
        $this->assertSame('completed', $this->finish($workspace, $operation)['treeOperation']['state']);
        $this->getJson($report)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $root);
        $this->getJson($report.'?tab=files')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($this->url($workspace).'?view=trash')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $root);
        $this->assertSame(1, DB::table('fms_entries')->where('public_id', $file)->count());
        $this->assertSame($deletedAt, DB::table('fms_entries')->where('public_id', $file)->value('trashed_at'));
        $restore = $this->start($workspace, $root, 'restore', 2);
        $this->assertSame('completed', $this->finish($workspace, $restore)['treeOperation']['state']);
        $this->getJson($report)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $file);
        $this->assertSame($deletedAt, DB::table('fms_entries')->where('public_id', $file)->value('trashed_at'));
    }

    public function test_cancel_releases_fence_and_no_partial_mutation_is_published(): void
    {
        config()->set('media-studio.trash.tree_batch_size', 10);
        $workspace = $this->workspace();
        $root = $this->folder($workspace, 'Root');
        for ($i = 0; $i < 12; $i++) {
            $this->folder($workspace, 'Child '.$i, $root);
        }
        $id = $this->start($workspace, $root);
        $this->postJson($this->url($workspace, 'tree-operations/'.$id.'/advance'))->assertJsonPath('treeOperation.state', 'preparing');
        $this->assertSame(0, DB::table('fms_entries')->whereNotNull('trashed_at')->count());
        $this->postJson($this->url($workspace, 'tree-operations/'.$id.'/cancel'))->assertOk()->assertJsonPath('treeOperation.state', 'cancelled');
        $this->postJson($this->url($workspace, 'folders'), ['name' => 'Writes restored'])->assertCreated();
        $this->getJson($this->url($workspace))->assertJsonCount(2, 'items');
    }

    public function test_changed_descendant_fails_without_removing_links_or_other_nodes(): void
    {
        $workspace = $this->workspace();
        $root = $this->folder($workspace, 'Root');
        $child = $this->folder($workspace, 'Child', $root);
        $id = $this->start($workspace, $root);
        DB::table('fms_entries')->where('public_id', $child)->update(['source_kind' => 'disk']);
        $this->assertSame('failed', $this->finish($workspace, $id)['treeOperation']['state']);
        $this->assertSame(0, DB::table('fms_entries')->whereNotNull('trashed_at')->count());
        $this->assertNull(DB::table('fms_workspaces')->value('tree_operation_id'));
    }

    public function test_scheduler_finishes_interrupted_operation_and_retention_is_opt_in(): void
    {
        $workspace = $this->workspace();
        $root = $this->folder($workspace, 'Root');
        $this->folder($workspace, 'Child', $root);
        $id = $this->start($workspace, $root);
        $this->artisan('media-studio:trash:maintain')->assertSuccessful();
        $this->assertDatabaseHas('fms_tree_operations', ['id' => $id, 'state' => 'completed']);
        $this->travel(31)->days();
        $this->artisan('media-studio:trash:maintain')->assertSuccessful();
        $this->assertDatabaseCount('fms_entries', 2);
        config()->set('media-studio.trash.retention_enabled', true);
        $this->artisan('media-studio:trash:maintain')->assertSuccessful();
        $this->assertDatabaseCount('fms_entries', 1);
        $this->assertNotNull(DB::table('fms_tree_operations')->where('id', $id)->value('purging_at'));
        $restore = $this->start($workspace, $root, 'restore', 2);
        $this->assertSame('failed', $this->finish($workspace, $restore)['treeOperation']['state']);
        $this->artisan('media-studio:trash:maintain')->assertSuccessful();
        $this->assertDatabaseCount('fms_entries', 0);
    }
}
