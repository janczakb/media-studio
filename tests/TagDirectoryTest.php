<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TagDirectoryTest extends TestCase
{
    public function test_directory_is_scoped_to_visible_assignments_and_supports_bounded_bidirectional_cursors(): void
    {
        $workspace = $this->workspace();
        $workspaceId = DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');
        $ids = [];
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $entry = DB::table('fms_entries')->insertGetId(['workspace_id' => $workspaceId, 'public_id' => (string) Str::ulid(), 'kind' => 'file', 'sort_group' => 1, 'name' => $name, 'normalized_name' => strtolower($name), 'source_kind' => 'managed', 'created_at' => now(), 'updated_at' => now()]);
            $tag = DB::table('fms_tags')->insertGetId(['workspace_id' => $workspaceId, 'public_id' => (string) Str::ulid(), 'locale' => 'en', 'name' => $name, 'normalized_name' => strtolower($name)]);
            DB::table('fms_entry_tags')->insert(['entry_id' => $entry, 'tag_id' => $tag]);
            $ids[] = $entry;
        }
        $first = $this->getJson($this->url($workspace, 'tags').'?limit=1')->assertOk()->assertJsonPath('items.0.label', 'Alpha');
        $second = $this->getJson($this->url($workspace, 'tags').'?'.http_build_query(['limit' => 1, 'cursor' => $first->json('nextCursor')]))->assertOk()->assertJsonPath('items.0.label', 'Beta');
        $this->getJson($this->url($workspace, 'tags').'?'.http_build_query(['limit' => 1, 'cursor' => $second->json('previousCursor')]))->assertOk()->assertJsonPath('items.0.label', 'Alpha');
        $this->getJson($this->url($workspace, 'tags').'?'.http_build_query(['search' => 'b', 'cursor' => $first->json('nextCursor')]))->assertUnprocessable();
        DB::table('fms_entries')->where('id', $ids[1])->update(['trashed_at' => now()]);
        $this->getJson($this->url($workspace, 'tags').'?search=be')->assertOk()->assertJsonCount(0, 'items');
        $this->actingAs(new GenericUser(['id' => 'other-user']))->getJson($this->url($workspace, 'tags'))->assertNotFound();
    }
}
