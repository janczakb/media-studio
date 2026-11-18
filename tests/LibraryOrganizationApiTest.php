<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class LibraryOrganizationApiTest extends TestCase
{
    public function test_personal_routes_keep_content_reads_separate_from_explicit_visits_and_do_not_change_library_revision(): void
    {
        $workspace = $this->workspace();
        $file = $this->withHeader('Idempotency-Key', 'organization-api-file-001')
            ->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->createWithContent('Opening notes.txt', 'A private file.')])
            ->assertCreated()->json();
        $entry = 'entries/'.$file['id'];
        $this->get($this->url($workspace, $entry.'/content'))->assertOk();
        $this->getJson($this->url($workspace).'?view=recent')->assertOk()->assertJsonCount(0, 'items');

        $this->postJson($this->url($workspace, $entry.'/visit'))->assertNoContent();
        $this->putJson($this->url($workspace, $entry.'/star'), ['starred' => true])->assertOk()->assertJsonPath('starred', true);
        foreach (['files', 'recent', 'starred'] as $view) {
            $this->getJson($this->url($workspace).'?view='.$view)
                ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $file['id'])
                ->assertJsonPath('items.0.starred', true)->assertJsonPath('items.0.revision', $file['revision']);
        }
        $this->postJson($this->url($workspace, 'entries/status'), ['ids' => [$file['id']]])
            ->assertOk()->assertJsonPath('items.0.starred', true);
        $this->getJson($this->url($workspace, $entry))->assertOk()->assertJsonPath('starred', true);
        $this->putJson($this->url($workspace, $entry.'/star'), ['starred' => false])->assertOk()->assertJsonPath('starred', false);
        $this->getJson($this->url($workspace).'?view=starred')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($this->url($workspace).'?view=recent')->assertOk()->assertJsonPath('items.0.starred', false);
    }

    public function test_bulk_route_requires_a_move_destination_and_persists_one_atomic_operation(): void
    {
        $workspace = $this->workspace();
        $destination = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Destination'])->assertCreated()->json('id');
        $one = $this->postJson($this->url($workspace, 'folders'), ['name' => 'One'])->assertCreated()->json('id');
        $two = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Two'])->assertCreated()->json('id');
        $items = [['id' => $one, 'revision' => 1], ['id' => $two, 'revision' => 1]];
        $url = $this->url($workspace, 'entries/bulk');

        $this->postJson($url, ['operation' => 'move', 'items' => $items])->assertUnprocessable();
        $this->postJson($url, ['operation' => 'move', 'items' => [...$items, $items[0]], 'parent' => $destination])->assertUnprocessable();
        $this->postJson($url, ['operation' => 'move', 'items' => $items, 'parent' => $destination])
            ->assertOk()->assertJsonCount(2, 'items');
        $this->getJson($this->url($workspace).'?parent='.$destination)->assertOk()->assertJsonCount(2, 'items');

        $this->postJson($url, ['operation' => 'move', 'items' => $items, 'parent' => null])->assertConflict();
        $this->assertSame(2, DB::table('fms_entries')->whereIn('public_id', [$one, $two])->whereNotNull('parent_id')->count());
        $updated = array_map(fn (array $item): array => [...$item, 'revision' => 2], $items);
        $this->postJson($url, ['operation' => 'move', 'items' => $updated, 'parent' => null])->assertOk();
        $this->getJson($this->url($workspace).'?parent='.$destination)->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_fullscreen_personal_navigation_follows_recent_order_across_folders(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Archive'])->assertCreated()->json('id');
        $files = [];
        foreach (['Alpha', 'Zulu', 'Bravo'] as $index => $name) {
            $files[$name] = $this->withHeader('Idempotency-Key', 'organization-nav-'.$name)
                ->postJson($this->url($workspace, 'uploads'), [
                    'file' => UploadedFile::fake()->createWithContent($name.'.txt', 'Private text content'),
                    'parent' => $index === 1 ? $folder : null,
                ])->assertCreated()->json('id');
            $this->postJson($this->url($workspace, 'entries/'.$files[$name].'/visit'))->assertNoContent();
            $this->travel(1)->seconds();
        }

        $this->getJson($this->url($workspace).'?view=recent')->assertOk()
            ->assertJsonPath('items.0.id', $files['Bravo'])
            ->assertJsonPath('items.1.id', $files['Zulu'])
            ->assertJsonPath('items.2.id', $files['Alpha']);
        $url = $this->url($workspace, 'entries/'.$files['Zulu'].'/neighbors');
        $this->getJson($url.'?view=recent&direction=next')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $files['Alpha']);
        $this->getJson($url.'?view=recent&direction=previous')->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $files['Bravo']);
        $this->getJson($url.'?view=starred&direction=next')->assertNotFound();
    }
}
