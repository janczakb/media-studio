<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Identity\Principal;

final class PhotoTimelineTest extends TestCase
{
    private function entry(string $workspace, string $name, string $media, string $date, ?string $parent = null): object
    {
        $wid = DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');
        $pid = $parent === null ? null : DB::table('fms_entries')->where('public_id', $parent)->value('id');
        $id = DB::table('fms_entries')->insertGetId([
            'workspace_id' => $wid, 'public_id' => (string) Str::ulid(),
            'parent_id' => $pid, 'parent_scope' => $pid ?? 0, 'kind' => 'file', 'sort_group' => 1,
            'name' => $name, 'normalized_name' => strtolower($name), 'media_type' => $media,
            'mime_type' => $media === 'image' ? 'image/jpeg' : ($media === 'video' ? 'video/mp4' : 'application/pdf'),
            'size_bytes' => 100, 'created_at' => $date, 'updated_at' => $date,
        ]);

        return DB::table('fms_entries')->where('id', $id)->first();
    }

    private function browse(string $workspace, array $photos = [], array $extra = [])
    {
        return $this->getJson($this->url($workspace).'?'.http_build_query(['view' => 'photos', 'photos' => $photos, ...$extra]));
    }

    public function test_all_images_videos_and_favorites_are_real_workspace_scoped_media_views(): void
    {
        $w = $this->workspace();
        $folder = $this->postJson($this->url($w, 'folders'), ['name' => 'Gallery'])->assertCreated()->json('id');
        $old = $this->entry($w, 'a-old.jpg', 'image', '2025-06-04 12:00:00');
        $video = $this->entry($w, 'b-video.mp4', 'video', '2026-05-03 12:00:00', $folder);
        $new = $this->entry($w, 'c-new.jpg', 'image', '2026-11-07 12:00:00');
        $document = $this->entry($w, 'document.pdf', 'application', '2026-11-07 13:00:00');
        $other = $this->workspace('Other');
        $this->entry($other, 'secret.jpg', 'image', '2026-11-07 14:00:00');
        $this->assertSame([$new->public_id, $video->public_id, $old->public_id], array_column($this->browse($w)->assertOk()->json('items'), 'id'));
        $this->browse($w, ['kind' => 'image'])->assertOk()->assertJsonCount(2, 'items');
        $this->browse($w, ['kind' => 'video'])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $video->public_id);
        foreach ([$video, $document] as $entry) {
            $this->putJson($this->url($w, 'entries/'.$entry->public_id.'/star'), ['starred' => true])->assertOk();
        }
        $this->browse($w, ['kind' => 'starred'])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $video->public_id);
        $this->postJson($this->url($w, 'entries/'.$new->public_id.'/trash'), ['revision' => 1])->assertOk();
        $this->browse($w)->assertOk()->assertJsonCount(2, 'items');
        $this->assertSame('2026-05-03 12:00:00', $this->browse($w, ['kind' => 'video'])->json('items.0.createdAt'));
    }

    public function test_period_buckets_are_inclusive_utc_and_continuations_bind_every_option(): void
    {
        $w = $this->workspace();
        $this->entry($w, 'prior.jpg', 'image', '2025-12-31 23:59:59');
        $a = $this->entry($w, 'start.jpg', 'image', '2026-01-01 00:00:00');
        $b = $this->entry($w, 'mid.mp4', 'video', '2026-11-01 12:00:00');
        $c = $this->entry($w, 'end.jpg', 'image', '2026-11-30 23:59:59');
        $this->entry($w, 'after.jpg', 'image', '2026-12-01 00:00:00');
        $this->browse($w, ['period' => 'months', 'bucket' => '2026-11'])->assertOk()->assertJsonCount(2, 'items');
        $this->browse($w, ['period' => 'days', 'bucket' => '2026-11-01'])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $b->public_id);
        $first = $this->browse($w, ['period' => 'years', 'bucket' => '2026'], ['limit' => 1])->assertOk();
        $cursor = $first->json('nextCursor');
        $this->assertNotNull($cursor);
        $this->browse($w, ['period' => 'years', 'bucket' => '2026'], ['cursor' => $cursor])->assertOk()->assertJsonCount(3, 'items');
        foreach ([['period' => 'years', 'bucket' => '2025'], ['period' => 'months', 'bucket' => '2026-11'], ['kind' => 'image', 'period' => 'years', 'bucket' => '2026']] as $changed) {
            $this->browse($w, $changed, ['cursor' => $cursor])->assertUnprocessable();
        }
        foreach ([['period' => 'days', 'bucket' => '2026-02-30'], ['period' => 'months', 'bucket' => '2026-13'], ['kind' => 'document'], ['period' => 'hours']] as $bad) {
            $this->browse($w, $bad)->assertUnprocessable();
        }
        $this->getJson($this->url($w).'?'.http_build_query(['view' => 'files', 'photos' => ['kind' => 'video']]))->assertUnprocessable();
    }

    public function test_preview_neighbors_share_chronology_kind_bucket_and_personal_favorites(): void
    {
        $w = $this->workspace();
        $a = $this->entry($w, 'z.jpg', 'image', '2026-11-01 12:00:00');
        $b = $this->entry($w, 'a.mp4', 'video', '2026-11-02 12:00:00');
        $c = $this->entry($w, 'b.jpg', 'image', '2026-11-03 12:00:00');
        $neighbor = fn ($entry, array $params) => $this->getJson($this->url($w, 'entries/'.$entry->public_id.'/neighbors').'?'.http_build_query(['view' => 'photos', ...$params]));
        $neighbor($c, ['direction' => 'next'])->assertOk()->assertJsonPath('items.0.id', $b->public_id)->assertJsonPath('items.1.id', $a->public_id);
        $neighbor($a, ['direction' => 'previous'])->assertOk()->assertJsonPath('items.0.id', $c->public_id)->assertJsonPath('items.1.id', $b->public_id);
        $neighbor($c, ['direction' => 'next', 'photos' => ['kind' => 'image']])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $a->public_id);
        $neighbor($b, ['direction' => 'next', 'photos' => ['kind' => 'image']])->assertNotFound();
        $neighbor($c, ['direction' => 'next', 'photos' => ['period' => 'days', 'bucket' => '2026-11-03']])->assertOk()->assertJsonCount(0, 'items');
        foreach ([$a, $c] as $entry) {
            $this->putJson($this->url($w, 'entries/'.$entry->public_id.'/star'), ['starred' => true])->assertOk();
        }
        $neighbor($c, ['direction' => 'next', 'photos' => ['kind' => 'starred']])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $a->public_id);
        $principal = new Principal('user', 'second-member');
        DB::table('fms_memberships')->insert(['workspace_id' => $a->workspace_id, 'principal_type' => $principal->type, 'principal_id' => $principal->id, 'principal_key' => $principal->key(), 'role' => 'viewer']);
        $this->actingAs(new GenericUser(['id' => 'second-member']));
        $this->browse($w, ['kind' => 'starred'])->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_photo_queries_use_the_timeline_indexes_without_storage_or_total_counts(): void
    {
        $w = $this->workspace();
        $this->entry($w, 'a.jpg', 'image', '2026-11-07 12:00:00');
        $wid = DB::table('fms_workspaces')->where('public_id', $w)->value('id');
        foreach ([['', 'fms_photo_timeline'], [" AND media_type='image'", 'fms_photo_type_timeline']] as [$type, $index]) {
            $plan = DB::select('EXPLAIN QUERY PLAN SELECT public_id FROM fms_entries WHERE workspace_id=? AND photo_media=1 AND trashed_at IS NULL'.$type.' ORDER BY photo_created DESC,id DESC LIMIT 61', [$wid]);
            $detail = implode(' ', array_column($plan, 'detail'));
            $this->assertStringContainsString($index, $detail);
            $this->assertStringNotContainsString('TEMP B-TREE', $detail);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->browse($w)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(3, count($queries));
        $this->assertStringNotContainsString('count(', strtolower(implode(' ', array_column($queries, 'query'))));
    }
}
