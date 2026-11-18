<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Cache\StudioCache;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Trash\PurgeTrashEntry;
use UseFlex\MediaStudio\Trash\TrashRetention;

final class ActivityTrashTest extends TestCase
{
    public function test_inbox_and_trash_cursors_are_scoped_to_the_view_and_principal(): void
    {
        $workspace = $this->workspace();
        $first = $this->upload($workspace, 'First brief.txt');
        $second = $this->upload($workspace, 'Second brief.txt');
        foreach ([$first, $second] as $entry) {
            $this->postJson($this->url($workspace, 'entries/'.$entry.'/trash'), ['revision' => 1])->assertOk();
        }

        $report = $this->url($workspace, 'trash-report');
        $page = $this->getJson($report.'?tab=files&actor=me&limit=1')->assertOk()
            ->assertJsonPath('items.0.id', $second)->assertJsonPath('items.0.actor.id', 'member-uuid-001')
            ->assertJsonPath('items.0.canRestore', true)->assertJsonPath('retention.days', 30)->json();
        $cursor = rawurlencode($page['nextCursor']);
        $previous = $this->getJson($report.'?tab=files&actor=me&limit=1&cursor='.$cursor)->assertOk()
            ->assertJsonPath('items.0.id', $first)->json('previousCursor');
        $this->getJson($report.'?tab=files&actor=me&limit=1&cursor='.rawurlencode($previous))->assertOk()->assertJsonPath('items.0.id', $second);
        $this->getJson($report.'?tab=folders&actor=me&limit=1&cursor='.$cursor)->assertUnprocessable();
        $this->getJson($report.'?after='.now()->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'items');

        $this->postJson($this->url($workspace, 'entries/'.$second.'/restore'), ['revision' => 2])->assertOk();
        $this->getJson($report.'?tab=restorations')->assertOk()->assertJsonPath('items.0.id', $second)->assertJsonPath('items.0.available', true);
        $inbox = $this->url($workspace, 'notifications');
        $notification = $this->getJson($inbox.'?unread=1&limit=1')->assertOk()->assertJsonPath('items.0.event', 'restored')->json('items.0.id');
        $this->patchJson($inbox.'/'.$notification, ['read' => true])->assertOk()->assertJsonPath('id', $notification);
        $this->getJson($inbox.'?unread=1')->assertOk()->assertJsonCount(4, 'items');
        $this->postJson($inbox.'/read-all', ['beforeId' => $notification])->assertOk()->assertJsonPath('updated', 4);
        $this->getJson($inbox.'?unread=1')->assertOk()->assertJsonCount(0, 'items');

        $other = new Principal('user', 'member-uuid-002');
        DB::table('fms_memberships')->insert(['workspace_id' => DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'),
            'principal_type' => $other->type, 'principal_id' => $other->id, 'principal_key' => $other->key(), 'role' => 'viewer']);
        $this->actingAs(new GenericUser(['id' => $other->id]));
        $this->getJson($inbox)->assertOk()->assertJsonCount(0, 'items');
        $this->patchJson($inbox.'/'.$notification, ['read' => false])->assertNotFound();
        $this->postJson($inbox.'/read-all', ['beforeId' => $notification])->assertNotFound();
        $this->getJson($report)->assertOk()->assertJsonPath('items.0.canRestore', false);
        $this->getJson($report.'?actor=me')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_retention_is_opt_in_and_resumes_without_removing_external_renditions(): void
    {
        $workspace = $this->workspace();
        $entry = $this->upload($workspace, 'Expired brief.txt');
        $this->postJson($this->url($workspace, 'entries/'.$entry.'/trash'), ['revision' => 1])->assertOk();
        DB::table('fms_entries')->where('public_id', $entry)->update(['trashed_at' => now()->subDays(31)]);
        $original = DB::table('fms_asset_versions')->first();
        $base = substr($original->object_key, 0, strpos($original->object_key, '/originals/'));
        $managedKey = $base.'/renditions/'.$original->id.'/'.str_repeat('a', 32).'/native';
        $externalKey = 'external/project-brief.webp';
        $rendition = ['entry_id' => $original->entry_id, 'version_id' => $original->id, 'state' => 'ready', 'created_at' => now(), 'updated_at' => now()];
        DB::table('fms_renditions')->insert([
            [...$rendition, 'preset' => 'native', 'owned' => true, 'disk' => 'second-test-disk', 'object_key' => $managedKey],
            [...$rendition, 'preset' => 'external', 'owned' => false, 'disk' => 'studio-test', 'object_key' => $externalKey],
        ]);
        Storage::disk('studio-test')->put($externalKey, 'Provider-owned image');
        $this->artisan('media-studio:trash:prune', ['workspace' => $workspace, 'principal-type' => 'user', 'principal-id' => 'member-uuid-001', '--apply' => true])->assertFailed();
        Storage::disk('studio-test')->assertExists($original->object_key);
        config(['media-studio.trash.retention_enabled' => true]);
        Queue::fake([PurgeTrashEntry::class]);
        $this->artisan('media-studio:trash:prune', ['workspace' => $workspace, 'principal-type' => 'user', 'principal-id' => 'member-uuid-001', '--apply' => true])->assertSuccessful();
        $purge = DB::table('fms_trash_purges')->first();
        Queue::assertPushed(PurgeTrashEntry::class, fn (PurgeTrashEntry $job): bool => $job->purgeId === $purge->id);
        $this->postJson($this->url($workspace, 'entries/'.$entry.'/restore'), ['revision' => 3])->assertConflict();
        $this->getJson($this->url($workspace, 'trash-report'))->assertOk()->assertJsonPath('items.0.canRestore', false);

        $retention = app(TrashRetention::class);
        DB::table('fms_memberships')->update(['role' => 'editor']);
        try {
            $retention->run($purge->id);
            $this->fail('A worker must recheck workspace owner access.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        Storage::disk('studio-test')->assertExists($original->object_key);
        DB::table('fms_memberships')->update(['role' => 'owner']);
        try {
            $retention->run($purge->id);
            $this->fail('The second storage driver is unavailable.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('fms_trash_purges', ['id' => $purge->id, 'state' => 'failed']);
            $this->assertSame(1, DB::table('fms_trash_purge_objects')->whereNotNull('deleted_at')->count());
        }
        Storage::disk('studio-test')->assertMissing($original->object_key);
        Storage::fake('second-test-disk')->put($managedKey, 'Native conversion');
        $retention->run($purge->id);
        $retention->run($purge->id);
        $this->assertDatabaseMissing('fms_entries', ['public_id' => $entry]);
        $this->assertDatabaseHas('fms_trash_purges', ['id' => $purge->id, 'state' => 'completed']);
        $this->assertSame(1, DB::table('fms_activity')->where('event', 'purged')->count());
        Storage::disk('second-test-disk')->assertMissing($managedKey);
        Storage::disk('studio-test')->assertExists($externalKey);
    }

    public function test_catalog_uses_selected_store_and_invalidates_on_fingerprint_change(): void
    {
        config(['cache.stores.studio-test' => ['driver' => 'array'], 'media-studio.cache.store' => 'studio-test', 'media-studio.cache.catalog_ttl' => 30]);
        $cache = app(StudioCache::class);
        $this->assertSame(['png'], $cache->catalog('extensions', 'v1', fn (): array => ['png']));
        $this->assertSame(['png'], $cache->catalog('extensions', 'v1', fn (): array => ['jpg']));
        $this->assertSame(['jpg'], $cache->catalog('extensions', 'v2', fn (): array => ['jpg']));
        config(['media-studio.cache.catalog_ttl' => 0]);
        $this->assertSame(['svg'], $cache->catalog('extensions', 'v1', fn (): array => ['svg']));
    }

    private function upload(string $workspace, string $name): string
    {
        return $this->withHeader('Idempotency-Key', 'activity-'.bin2hex(random_bytes(16)))
            ->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->createWithContent($name, 'Project brief')])
            ->assertCreated()->json('id');
    }
}
