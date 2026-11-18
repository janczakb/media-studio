<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Identity\Principal;

final class PersonalLibraryTest extends TestCase
{
    public function test_viewers_can_set_private_stars_without_mutating_library_or_another_principal(): void
    {
        $workspace = $this->workspace();
        $file = $this->upload($workspace, 'Media.txt');
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'My folder'])->assertCreated()->json();
        $viewer = new class(['id' => 'member-uuid-001']) extends GenericUser {};
        config()->set('media-studio.principals.guest', $viewer::class);
        $guest = new Principal('guest', 'member-uuid-001');
        $this->member($workspace, $guest);
        $this->actingAs($viewer);
        $path = $this->url($workspace, 'entries/'.$file['id']);
        $this->putJson($path.'/star', ['starred' => true])->assertOk()->assertExactJson(['id' => $file['id'], 'starred' => true]);
        $this->putJson($this->url($workspace, 'entries/'.$folder['id'].'/star'), ['starred' => true])->assertOk();
        $this->postJson($this->url($workspace, 'entries/'.$folder['id'].'/visit'))->assertUnprocessable();
        $this->getJson($path)->assertOk()->assertJsonPath('starred', true)->assertJsonPath('revision', $file['revision']);
        $this->postJson($this->url($workspace, 'entries/status'), ['ids' => [$file['id']]])->assertOk()->assertJsonPath('items.0.starred', true);
        $this->patchJson($path, ['revision' => $file['revision'], 'name' => 'Changed.txt'])->assertForbidden();
        $this->getJson($this->url($workspace).'?view=starred')->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('canWrite', false);

        $this->actingAs(new GenericUser(['id' => 'member-uuid-001']));
        $this->getJson($path)->assertOk()->assertJsonPath('starred', false);
        $this->getJson($this->url($workspace).'?view=starred')->assertOk()->assertJsonCount(0, 'items');

        DB::table('fms_memberships')->where('principal_key', $guest->key())->delete();
        $this->actingAs($viewer);
        $this->getJson($this->url($workspace).'?view=starred')->assertNotFound();
        $this->putJson($path.'/star', ['starred' => false])->assertNotFound();
        $this->postJson($path.'/visit')->assertNotFound();
    }

    public function test_recents_follow_explicit_preview_events_and_keep_live_entry_metadata(): void
    {
        $workspace = $this->workspace();
        $file = $this->upload($workspace, 'First.txt');
        $other = $this->upload($workspace, 'Second.txt');
        $entryUrl = $this->url($workspace, 'entries/'.$file['id']);
        $otherUrl = $this->url($workspace, 'entries/'.$other['id']);
        $recent = $this->url($workspace).'?view=recent';
        $this->get($entryUrl.'/content')->assertOk();
        $this->getJson($entryUrl)->assertOk();
        $this->getJson($recent)->assertOk()->assertJsonCount(0, 'items');
        $this->postJson($entryUrl.'/visit')->assertNoContent();
        $this->travel(1)->seconds();
        $this->postJson($otherUrl.'/visit')->assertNoContent();
        $this->getJson($recent)->assertOk()->assertJsonPath('items.0.id', $other['id'])->assertJsonPath('items.1.id', $file['id']);
        $this->travel(1)->seconds();
        $this->postJson($entryUrl.'/visit')->assertNoContent();
        $this->putJson($entryUrl.'/star', ['starred' => true])->assertOk();
        $this->patchJson($entryUrl, ['revision' => $file['revision'], 'name' => 'Renamed.txt'])->assertOk();
        $this->getJson($recent)->assertOk()->assertJsonPath('items.0.name', 'Renamed.txt')->assertJsonPath('items.0.starred', true);
        $this->putJson($entryUrl.'/star', ['starred' => false])->assertOk();
        $this->getJson($recent)->assertOk()->assertJsonPath('items.0.starred', false);
        $this->assertDatabaseCount('fms_personal_entries', 2);

        $this->postJson($entryUrl.'/trash', ['revision' => $file['revision'] + 1])->assertOk();
        $this->getJson($recent)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $other['id']);
        $this->postJson($entryUrl.'/visit')->assertNotFound();
        $this->postJson($entryUrl.'/restore', ['revision' => $file['revision'] + 2])->assertOk();
        $this->getJson($recent)->assertOk()->assertJsonPath('items.0.name', 'Renamed.txt');
    }

    public function test_personal_keyset_binds_scope_and_supports_backwards_pages_and_preview(): void
    {
        $this->freezeTime();
        $workspace = $this->workspace();
        $otherWorkspace = $this->workspace('Other workspace');
        $files = [];
        foreach (['first.txt', 'second.txt', 'third.txt', 'fourth.txt'] as $name) {
            $id = $this->upload($workspace, $name)['id'];
            $this->putJson($this->url($workspace, 'entries/'.$id.'/star'), ['starred' => true])->assertOk();
            $files[] = $id;
        }
        $starred = $this->url($workspace).'?view=starred&limit=2';
        $first = $this->getJson($starred)->assertOk()->assertJsonPath('items.0.id', $files[3])->assertJsonPath('items.1.id', $files[2])->json();
        $cursor = urlencode($first['nextCursor']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $second = $this->getJson($starred.'&cursor='.$cursor)->assertOk()->assertJsonPath('items.0.id', $files[1])
            ->assertJsonPath('items.1.id', $files[0])->assertJsonPath('hasMore', false)->json();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $queries);
        $pageQuery = collect($queries)->first(fn (array $query): bool => str_contains($query['query'], 'fms_personal_entries'));
        $plan = DB::select('EXPLAIN QUERY PLAN '.$pageQuery['query'], $pageQuery['bindings']);
        $planText = implode(' ', array_column($plan, 'detail'));
        $this->assertStringContainsString('fms_personal_stars', $planText);
        $this->assertStringNotContainsString('TEMP B-TREE', $planText);
        $this->getJson($starred.'&cursor='.urlencode($second['previousCursor']))->assertOk()->assertJsonPath('items.0.id', $files[3])
            ->assertJsonPath('items.1.id', $files[2])->assertJsonPath('previousCursor', null);
        $this->getJson($this->url($otherWorkspace).'?view=starred&cursor='.$cursor)->assertUnprocessable();
        $this->getJson($this->url($workspace).'?view=recent&cursor='.$cursor)->assertUnprocessable();
        $this->getJson($starred.'&search=third&cursor='.$cursor)->assertUnprocessable();
        $this->getJson($starred.'&cursor=invalid')->assertUnprocessable();
        $this->getJson($this->url($workspace, 'entries/'.$files[2].'/neighbors').'?view=starred&direction=next&limit=2')
            ->assertOk()->assertJsonPath('items.0.id', $files[1])->assertJsonPath('items.1.id', $files[0]);
        $this->getJson($this->url($workspace, 'entries/'.$files[0].'/neighbors').'?view=starred&direction=previous&limit=2')
            ->assertOk()->assertJsonPath('items.0.id', $files[2])->assertJsonPath('items.1.id', $files[1]);

        $caseVariant = new Principal('user', 'Member-uuid-001');
        $this->member($workspace, $caseVariant);
        $this->actingAs(new GenericUser(['id' => $caseVariant->id]));
        $this->getJson($starred.'&cursor='.$cursor)->assertUnprocessable();
        $this->getJson($starred)->assertOk()->assertJsonCount(0, 'items');
        $this->putJson($this->url($otherWorkspace, 'entries/'.$files[0].'/star'), ['starred' => true])->assertNotFound();
        $this->actingAs(new GenericUser(['id' => 'member-uuid-001']));
        $this->putJson($this->url($otherWorkspace, 'entries/'.$files[0].'/star'), ['starred' => true])->assertNotFound();
        $this->travel(61)->minutes();
        $this->getJson($starred.'&cursor='.$cursor)->assertUnprocessable();
    }

    /** @return array{id: string, revision: int} */
    private function upload(string $workspace, string $name): array
    {
        return $this->withHeader('Idempotency-Key', 'personal-library-'.sha1($name))
            ->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->createWithContent($name, 'A plain text file.')])
            ->assertCreated()->json();
    }

    private function member(string $workspace, Principal $principal): void
    {
        DB::table('fms_memberships')->insert(['workspace_id' => DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'),
            'principal_type' => $principal->type, 'principal_id' => $principal->id, 'principal_key' => $principal->key(), 'role' => 'viewer']);
    }
}
