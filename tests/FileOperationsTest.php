<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class FileOperationsTest extends TestCase
{
    private function file(string $workspace, string $name = 'brief.txt'): array
    {
        return $this->withHeader('Idempotency-Key', 'upload-test-'.bin2hex(random_bytes(16)))
            ->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->createWithContent($name, 'Original project brief')])
            ->assertCreated()->json();
    }

    public function test_rename_and_metadata_preserve_bytes_and_stale_edits_do_not_overwrite(): void
    {
        $workspace = $this->workspace();
        $entry = $this->file($workspace);
        $url = $this->url($workspace, 'entries/'.$entry['id']);
        $version = DB::table('fms_asset_versions')->first();
        $this->patchJson($url, ['revision' => 1, 'name' => 'Launch brief.txt', 'metadata' => ['alt' => 'A useful description', 'caption' => 'Campaign brief']])->assertOk()->assertJsonPath('revision', 2);
        $this->patchJson($url, ['revision' => 1, 'name' => 'Outdated change.txt'])->assertConflict();
        $this->getJson($url)->assertOk()->assertJsonPath('name', 'Launch brief.txt')->assertJsonPath('metadata.alt', 'A useful description');
        $this->assertSame('Original project brief', Storage::disk('studio-test')->get($version->object_key));
        $this->assertDatabaseCount('fms_asset_versions', 1);
        $this->patchJson($url, ['revision' => 2, 'metadata' => ['script' => 'Unknown key']])->assertUnprocessable();
        $this->patchJson($url, ['revision' => 2, 'name' => '../invalid'])->assertUnprocessable();
    }

    public function test_drawing_is_bound_to_the_image_version_and_survives_metadata_edits(): void
    {
        $workspace = $this->workspace();
        $entry = $this->withHeader('Idempotency-Key', 'drawing-image-fixture-123')
            ->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->image('image.png', 80, 60)])
            ->assertCreated()->json();
        $url = $this->url($workspace, 'entries/'.$entry['id']);
        $version = DB::table('fms_asset_versions')->where('id', $entry['versionId'])->first();
        $original = Storage::disk($version->disk)->get($version->object_key);
        $drawing = ['versionId' => $entry['versionId'], 'marks' => [
            ['tool' => 'pen', 'color' => '#4169e1', 'points' => [[20, 30], [500, 600]]],
        ]];
        $this->patchJson($url, ['revision' => 1, 'drawing' => [...$drawing, 'versionId' => (string) Str::ulid()]])->assertConflict();
        $this->patchJson($url, ['revision' => 1, 'drawing' => [...$drawing, 'marks' => array_fill(0, 65, $drawing['marks'][0])]])->assertUnprocessable();
        foreach ([['opacity' => 2], ['strokeWidth' => -1], ['fontFamily' => 'url(evil)'], ['fill' => 'invalid']] as $invalidStyle) {
            $this->patchJson($url, ['revision' => 1, 'drawing' => [...$drawing, 'marks' => [[...$drawing['marks'][0], ...$invalidStyle]]]])->assertUnprocessable();
        }
        $drawing['marks'][0] = [...$drawing['marks'][0], 'opacity' => 0.5, 'strokeWidth' => 5, 'fill' => 'semi', 'dash' => 'dotted'];
        $drawing['marks'][] = ['tool' => 'rectangle', 'color' => '#202124', 'points' => [[100, 100], [400, 500]], 'text' => 'Reviewed', 'fontSize' => 32, 'fontFamily' => 'serif', 'textAlign' => 'middle', 'verticalAlign' => 'middle'];
        $this->patchJson($url, ['revision' => 1, 'drawing' => $drawing])->assertOk()->assertJsonPath('revision', 2);
        $this->patchJson($url, ['revision' => 2, 'metadata' => ['caption' => 'Reviewed']])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('metadata.drawing', $drawing)->assertJsonPath('metadata.caption', 'Reviewed');
        $this->assertSame($original, Storage::disk($version->disk)->get($version->object_key));
        $this->assertDatabaseCount('fms_asset_versions', 1);
    }

    public function test_move_validates_workspace_and_prevents_cycles_and_name_collision(): void
    {
        $workspace = $this->workspace();
        $root = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Root'])->assertCreated()->json();
        $child = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Child', 'parent' => $root['id']])->assertCreated()->json();
        $rootUrl = $this->url($workspace, 'entries/'.$root['id']);
        $this->patchJson($rootUrl, ['revision' => 1, 'parent' => $child['id']])->assertUnprocessable();
        $this->patchJson($rootUrl, ['revision' => 1, 'parent' => $root['id']])->assertUnprocessable();
        $entry = $this->file($workspace);
        $url = $this->url($workspace, 'entries/'.$entry['id']);
        $this->patchJson($url, ['revision' => 1, 'parent' => $child['id']])->assertOk()->assertJsonPath('revision', 2);
        $this->getJson($this->url($workspace).'?parent='.$child['id'])->assertJsonPath('items.0.id', $entry['id']);
        $this->getJson($url)->assertJsonPath('parentId', $child['id']);
        $other = $this->workspace('Other');
        $foreign = $this->postJson($this->url($other, 'folders'), ['name' => 'Private'])->assertCreated()->json('id');
        $this->patchJson($url, ['revision' => 2, 'parent' => $foreign])->assertNotFound();
        $collision = $this->file($workspace, 'brief.txt');
        $this->patchJson($url, ['revision' => 2, 'parent' => null])->assertConflict();
        $this->getJson($url)->assertJsonPath('revision', 2)->assertJsonPath('parentId', $child['id']);
        $this->assertNotSame($entry['id'], $collision['id']);
    }

    public function test_trash_is_reversible_and_blocks_serving_without_deleting_originals(): void
    {
        $workspace = $this->workspace();
        $entry = $this->file($workspace);
        $url = $this->url($workspace, 'entries/'.$entry['id']);
        $this->postJson($url.'/trash', ['revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->getJson($this->url($workspace))->assertJsonCount(0, 'items');
        $this->getJson($this->url($workspace).'?view=trash')->assertJsonPath('items.0.id', $entry['id']);
        $this->getJson($url.'/content')->assertNotFound();
        $this->patchJson($url, ['revision' => 2, 'name' => 'Changed.txt'])->assertConflict();
        $this->assertCount(1, Storage::disk('studio-test')->allFiles());
        $this->postJson($url.'/restore', ['revision' => 1])->assertConflict();
        $this->postJson($url.'/restore', ['revision' => 2])->assertOk()->assertJsonPath('revision', 3)->assertJsonPath('trashedAt', null);
        $this->get($url.'/content')->assertOk();
        $this->getJson($this->url($workspace).'?view=trash')->assertJsonCount(0, 'items');
    }

    public function test_nonempty_folder_is_not_trashed_by_an_unbounded_request(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Projects'])->assertCreated()->json('id');
        $this->postJson($this->url($workspace, 'folders'), ['name' => 'Launch', 'parent' => $folder])->assertCreated();
        $id = $this->postJson($this->url($workspace, 'entries/'.$folder.'/trash'), ['revision' => 1])->assertStatus(202)->json('treeOperation.id');
        $this->getJson($this->url($workspace))->assertJsonCount(0, 'items');
        $this->assertSame(0, DB::table('fms_entries')->whereNotNull('trashed_at')->count());
        $this->postJson($this->url($workspace, 'tree-operations/'.$id.'/advance'))->assertOk()->assertJsonPath('treeOperation.state', 'completed');
        $this->assertSame(2, DB::table('fms_entries')->whereNotNull('trashed_at')->count());
    }

    public function test_viewer_and_unrelated_workspace_cannot_mutate_or_discover_private_details(): void
    {
        $workspace = $this->workspace();
        $entry = $this->file($workspace);
        $url = $this->url($workspace, 'entries/'.$entry['id']);
        DB::table('fms_memberships')->update(['role' => 'viewer']);
        $this->getJson($url)->assertOk();
        $this->patchJson($url, ['revision' => 1, 'name' => 'No.txt'])->assertForbidden();
        $this->postJson($url.'/trash', ['revision' => 1])->assertForbidden();
        $this->postJson($url.'/restore', ['revision' => 1])->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 'Someone else']));
        $this->getJson($url)->assertNotFound();
        $this->postJson($url.'/trash', ['revision' => 1])->assertNotFound();
    }

    public function test_case_sensitive_principal_ids_have_distinct_membership_keys(): void
    {
        $this->actingAs(new GenericUser(['id' => 'Member-ABC']));
        $first = $this->workspace('First');
        $this->actingAs(new GenericUser(['id' => 'member-abc']));
        $second = $this->workspace('Second');
        $this->getJson('/media-studio/api/v1/workspaces')->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $second);
        $this->getJson($this->url($first))->assertNotFound();
        $this->assertNotSame(...DB::table('fms_memberships')->pluck('principal_key')->all());
    }

    public function test_content_supports_bounded_byte_ranges_and_rejects_invalid_ranges(): void
    {
        $workspace = $this->workspace();
        $entry = $this->file($workspace);
        $url = $this->url($workspace, 'entries/'.$entry['id'].'/content');
        $response = $this->withHeader('Range', 'bytes=9-15')->get($url)->assertStatus(206)->assertHeader('Content-Range', 'bytes 9-15/22')->assertHeader('Content-Length', '7');
        $this->assertSame('project', $response->streamedContent());
        $suffix = $this->withHeader('Range', 'bytes=-5')->get($url)->assertStatus(206);
        $this->assertSame('brief', $suffix->streamedContent());
        $this->withHeader('Range', 'bytes=200-300')->get($url)->assertStatus(416);
        $this->withHeader('Range', 'bytes=0-1,5-8')->get($url)->assertStatus(416);
        $this->withHeader('Range', 'bytes=-0')->get($url)->assertStatus(416);
    }
}
