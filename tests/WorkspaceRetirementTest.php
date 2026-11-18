<?php

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Workspaces\WorkspaceRetirement;

final class WorkspaceRetirementTest extends TestCase
{
    public function test_move_preserves_identity_bytes_and_hierarchy_then_removes_source(): void
    {
        $source = $this->workspace('Source');
        $target = $this->workspace('Destination');
        $folder = $this->postJson($this->url($source, 'folders'), ['name' => 'Nested'])->assertCreated()->json('id');
        $file = $this->withHeader('Idempotency-Key', (string) Str::ulid())->postJson($this->url($source, 'uploads'), ['parent' => $folder, 'file' => UploadedFile::fake()->image('sample.png', 20, 20)])->assertCreated()->json('id');
        $entry = DB::table('fms_entries')->where('public_id', $file)->first();
        $version = DB::table('fms_asset_versions')->where('id', $entry->current_version_id)->first();
        $bytes = Storage::disk($version->disk)->get($version->object_key);
        $targetId = DB::table('fms_workspaces')->where('public_id', $target)->value('id');
        $tag = DB::table('fms_tags')->insertGetId(['public_id' => (string) Str::ulid(), 'workspace_id' => $entry->workspace_id, 'locale' => 'en', 'name' => 'Keep tag', 'normalized_name' => 'keep tag']);
        DB::table('fms_entry_tags')->insert(['entry_id' => $entry->id, 'tag_id' => $tag]);
        $comment = (string) Str::ulid();
        DB::table('fms_comments')->insert(['public_id' => $comment, 'workspace_id' => $entry->workspace_id, 'entry_id' => $entry->id, 'author_key' => str_repeat('a', 64), 'author_type' => 'user', 'author_id' => 'member-uuid-001', 'body' => 'Preserve comment', 'mentions' => '[]', 'request_key' => str_repeat('c', 64), 'created_at' => now(), 'updated_at' => now()]);
        $share = $this->postJson($this->url($source, 'shares'), ['title' => 'Published', 'entryIds' => [$file], 'visibility' => 'publish'])->assertCreated()->json();
        $op = $this->postJson($this->url($source, 'retirement'), ['confirmName' => 'Source', 'mode' => 'move', 'target' => $target])->assertAccepted()->json('id');
        $this->postJson($this->url($source, 'folders'), ['name' => 'Blocked'])->assertStatus(423);
        $this->getJson($share['url'])->assertNotFound();
        $this->finish($op);
        $new = DB::table('fms_asset_versions')->where('id', $version->id)->first();
        $this->assertNotSame($version->object_key, $new->object_key);
        $this->assertSame($bytes, Storage::disk($new->disk)->get($new->object_key));
        Storage::disk($version->disk)->assertMissing($version->object_key);
        $this->assertDatabaseMissing('fms_workspaces', ['public_id' => $source]);
        $this->assertDatabaseHas('fms_comments', ['public_id' => $comment, 'workspace_id' => $targetId, 'body' => 'Preserve comment']);
        $this->assertSame('Keep tag', DB::table('fms_entry_tags as et')->join('fms_tags as t', 't.id', '=', 'et.tag_id')->where('et.entry_id', $entry->id)->where('t.workspace_id', $targetId)->value('t.name'));
        $this->getJson($this->url($target, 'entries/'.$file))->assertOk()->assertJsonPath('id', $file);
        $this->assertDatabaseHas('fms_entries', ['id' => $entry->id, 'current_version_id' => $entry->current_version_id]);
    }

    public function test_delete_removes_owned_objects_and_shares_but_rejects_wrong_confirmation_and_target(): void
    {
        $source = $this->workspace('Remove');
        $file = $this->withHeader('Idempotency-Key', (string) Str::ulid())->postJson($this->url($source, 'uploads'), ['file' => UploadedFile::fake()->image('delete.png')])->assertCreated()->json('id');
        $version = DB::table('fms_asset_versions')->whereIn('entry_id', DB::table('fms_entries')->select('id')->where('public_id', $file))->first();
        $this->postJson($this->url($source, 'retirement'), ['confirmName' => 'Wrong', 'mode' => 'delete'])->assertUnprocessable();
        $this->postJson($this->url($source, 'retirement'), ['confirmName' => 'Remove', 'mode' => 'move', 'target' => $source])->assertUnprocessable();
        $op = $this->postJson($this->url($source, 'retirement'), ['confirmName' => 'Remove', 'mode' => 'delete'])->assertAccepted()->json('id');
        $this->finish($op);
        Storage::disk($version->disk)->assertMissing($version->object_key);
        $this->assertDatabaseMissing('fms_entries', ['public_id' => $file]);
    }

    public function test_worker_stops_on_revoked_ownership_and_can_resume_after_owner_reauthorization(): void
    {
        $source = $this->workspace('Paused');
        $op = $this->postJson($this->url($source, 'retirement'), ['confirmName' => 'Paused', 'mode' => 'delete'])->assertAccepted()->json('id');
        $wid = DB::table('fms_workspaces')->where('public_id', $source)->value('id');
        DB::table('fms_memberships')->where('workspace_id', $wid)->update(['role' => 'viewer']);
        app(WorkspaceRetirement::class)->step($op);
        $this->assertDatabaseHas('fms_workspace_operations', ['id' => $op, 'state' => 'failed']);
        $this->assertDatabaseHas('fms_workspaces', ['id' => $wid]);
        DB::table('fms_memberships')->where('workspace_id', $wid)->update(['role' => 'owner']);
        $this->postJson('/media-studio/api/v1/workspace-operations/'.$op.'/retry')->assertOk();
        $this->finish($op);
    }

    private function finish(string $id): void
    {
        for ($i = 0; $i < 30; $i++) {
            app(WorkspaceRetirement::class)->step($id);
            $row = DB::table('fms_workspace_operations')->where('id', $id)->first();
            $this->assertNotSame('failed', $row->state, $row->error ?? '');
            if ($row->state === 'completed') {
                return;
            }
        }
        $this->fail('Retirement did not complete in bounded fixture steps.');
    }
}
