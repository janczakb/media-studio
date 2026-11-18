<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Navigation\NavigationChanges;
use UseFlex\MediaStudio\Navigation\NavigationPublisher;

final class NavigationOperationsTest extends TestCase
{
    public function test_replayed_move_returns_the_original_receipt_and_undo_checks_current_revision(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Source'])->assertCreated()->json('id');
        $destination = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Destination'])->assertCreated()->json('id');
        $input = ['requestId' => (string) Str::uuid(), 'revision' => 1, 'parent' => $destination];
        $first = $this->patchJson($this->url($workspace, 'entries/'.$folder), $input)->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('undoAvailable', true)->json();
        $this->patchJson($this->url($workspace, 'entries/'.$folder), $input)->assertOk()->assertJsonPath('operationId', $first['operationId'])->assertJsonPath('revision', 2);
        $this->assertDatabaseCount('fms_navigation_operations', 1);
        $this->patchJson($this->url($workspace, 'entries/'.$folder), [...$input, 'parent' => null])->assertConflict();
        $this->postJson($this->url($workspace, 'navigation/operations/'.$first['operationId'].'/undo'))->assertOk()->assertJsonPath('undone', true);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $folder, 'parent_id' => null, 'revision' => 3]);
        $this->postJson($this->url($workspace, 'navigation/operations/'.$first['operationId'].'/undo'))->assertOk();
        $this->assertDatabaseHas('fms_entries', ['public_id' => $folder, 'revision' => 3]);
    }

    public function test_undo_does_not_overwrite_a_later_edit_or_accept_a_different_actor(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Original'])->assertCreated()->json('id');
        $result = $this->patchJson($this->url($workspace, 'entries/'.$folder), ['requestId' => (string) Str::uuid(), 'revision' => 1, 'name' => 'Changed'])->assertOk()->json();
        $this->patchJson($this->url($workspace, 'entries/'.$folder), ['revision' => 2, 'name' => 'Later edit'])->assertOk();
        $this->postJson($this->url($workspace, 'navigation/operations/'.$result['operationId'].'/undo'))->assertConflict();
        $this->assertDatabaseHas('fms_entries', ['public_id' => $folder, 'name' => 'Later edit', 'revision' => 3]);
        $this->actingAs(new GenericUser(['id' => 'intruder']));
        $this->postJson($this->url($workspace, 'navigation/operations/'.$result['operationId'].'/undo'))->assertNotFound();
    }

    public function test_bulk_receipts_are_atomic_and_replay_without_another_revision_increment(): void
    {
        $workspace = $this->workspace();
        $first = $this->postJson($this->url($workspace, 'folders'), ['name' => 'First'])->assertCreated()->json('id');
        $second = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Second'])->assertCreated()->json('id');
        $destination = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Destination'])->assertCreated()->json('id');
        $input = ['requestId' => (string) Str::uuid(), 'operation' => 'move', 'parent' => $destination, 'items' => [['id' => $first, 'revision' => 1], ['id' => $second, 'revision' => 1]]];
        $result = $this->postJson($this->url($workspace, 'entries/bulk'), $input)->assertOk()->assertJsonCount(2, 'items')->json();
        $this->postJson($this->url($workspace, 'entries/bulk'), $input)->assertOk()->assertJsonPath('items.0.revision', 2);
        $this->postJson($this->url($workspace, 'navigation/operations/'.$result['operationId'].'/undo'))->assertOk();
        $this->assertDatabaseHas('fms_entries', ['public_id' => $first, 'parent_id' => null, 'revision' => 3]);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $second, 'parent_id' => null, 'revision' => 3]);
    }

    public function test_delta_replay_detects_missing_history_and_is_bounded_and_private(): void
    {
        $workspace = $this->workspace();
        $head = $this->getJson($this->url($workspace, 'navigation/changes').'?after=0')->assertOk()->json('nextRevision');
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Original'])->assertCreated()->json('id');
        $page = $this->getJson($this->url($workspace, 'navigation/changes').'?after='.$head)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.payload.nodes.0.id', $folder)->json();
        $this->assertSame($head + 1, $page['nextRevision']);
        DB::table('fms_navigation_changes')->delete();
        $this->getJson($this->url($workspace, 'navigation/changes').'?after='.$head)->assertOk()->assertJsonPath('reset', true);
        $this->actingAs(new GenericUser(['id' => 'intruder']));
        $this->getJson($this->url($workspace, 'navigation/changes').'?after=0')->assertNotFound();
    }

    public function test_delta_replay_has_a_byte_budget_as_well_as_an_event_limit(): void
    {
        $workspace = $this->workspace();
        $wid = (int) DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');
        DB::transaction(function () use ($wid): void {
            app(NavigationChanges::class)->record($wid, 'test', ['value' => str_repeat('x', 200000)]);
            app(NavigationChanges::class)->record($wid, 'test', ['value' => str_repeat('x', 200000)]);
        });
        $page = $this->getJson($this->url($workspace, 'navigation/changes').'?after=1')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('hasMore', true);
        $this->assertLessThan(210000, strlen($page->getContent()));
    }

    public function test_outbox_retries_a_failed_publisher_without_rolling_back_the_mutation(): void
    {
        $workspace = $this->workspace();
        config()->set('media-studio.navigation.publisher', NavigationPublisherFixture::class);
        NavigationPublisherFixture::$fail = true;
        NavigationPublisherFixture::$calls = [];
        $this->postJson($this->url($workspace, 'folders'), ['name' => 'Published'])->assertCreated();
        $this->artisan('media-studio:navigation:maintain')->assertSuccessful();
        $this->assertDatabaseHas('fms_navigation_changes', ['attempts' => 1, 'published_at' => null]);
        $this->assertDatabaseHas('fms_entries', ['name' => 'Published']);
        NavigationPublisherFixture::$fail = false;
        $this->travel(10)->seconds();
        $this->artisan('media-studio:navigation:maintain')->assertSuccessful();
        $this->assertNotNull(DB::table('fms_navigation_changes')->value('published_at'));
        $this->assertSame([[$workspace, 2], [$workspace, 2]], NavigationPublisherFixture::$calls);
    }

    public function test_retention_removes_only_a_bounded_expired_batch(): void
    {
        $workspace = $this->workspace();
        $this->postJson($this->url($workspace, 'folders'), ['name' => 'Original'])->assertCreated();
        DB::table('fms_navigation_changes')->update(['created_at' => now()->subDays(9)]);
        $this->artisan('media-studio:navigation:maintain')->assertSuccessful();
        $this->assertDatabaseCount('fms_navigation_changes', 0);
    }
}

final class NavigationPublisherFixture implements NavigationPublisher
{
    public static bool $fail = false;

    public static array $calls = [];

    public function publish(string $workspace, int $revision): void
    {
        self::$calls[] = [$workspace, $revision];
        if (self::$fail) {
            throw new \RuntimeException('Synthetic publisher failure.');
        }
    }
}
