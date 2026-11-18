<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Processing\GenerateThumbnail;
use UseFlex\MediaStudio\Processing\ThumbnailProcessor;

final class ThumbnailsAndNavigationTest extends TestCase
{
    public function test_folder_import_preserves_structure_and_retries_reuse_folders(): void
    {
        $workspace = $this->workspace();
        $url = $this->url($workspace, 'folders/resolve');
        $paths = ['Campaign/Photos', 'Campaign/Documents', 'Campaign/Photos/Portraits'];
        $a = $this->postJson($url, ['paths' => $paths])->assertOk();
        $b = $this->postJson($url, ['paths' => $paths])->assertOk();
        $this->assertSame($a->json(), $b->json());
        $this->assertDatabaseCount('fms_entries', 4);
        $campaign = $this->getJson($this->url($workspace).'?view=folders')->assertJsonCount(1, 'items')->json('items.0.id');
        $this->getJson($this->url($workspace).'?view=folders&parent='.$campaign)->assertJsonCount(2, 'items');
        $before = DB::table('fms_entries')->count();
        foreach (['../private', 'A//B', '/absolute', 'A/../secret', 'A\\B', 'A/ ', implode('/', array_fill(0, 501, 'A'))] as $invalid) {
            $this->postJson($url, ['paths' => [$invalid]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('fms_entries', $before);
        DB::table('fms_memberships')->update(['role' => 'viewer']);
        $this->postJson($url, ['paths' => ['New']])->assertForbidden();
    }

    public function test_neighbors_are_file_only_bounded_and_ordered_without_offsets(): void
    {
        $workspace = $this->workspace();
        $workspaceId = DB::table('fms_workspaces')->value('id');
        $ids = [];
        $rows = [];
        for ($i = 0; $i < 150; $i++) {
            $name = sprintf('Image %03d', $i);
            $ids[$i] = (string) Str::ulid();
            $rows[] = ['public_id' => $ids[$i], 'workspace_id' => $workspaceId, 'parent_scope' => 0, 'kind' => 'file', 'sort_group' => 1, 'name' => $name, 'normalized_name' => strtolower($name), 'media_type' => 'image', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('fms_entries')->insert($rows);
        $this->postJson($this->url($workspace, 'folders'), ['name' => 'Image 070 folder'])->assertCreated();
        DB::enableQueryLog();
        $a = $this->getJson($this->url($workspace, 'entries/'.$ids[70].'/neighbors').'?direction=next')->assertOk()->assertJsonCount(60, 'items')->assertJsonPath('items.0.id', $ids[71])->assertJsonPath('hasMore', true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(4, count($queries));
        $this->assertStringNotContainsString('offset', strtolower(implode(' ', array_column($queries, 'query'))));
        $this->getJson($this->url($workspace, 'entries/'.$ids[70].'/neighbors').'?direction=previous&limit=10')->assertJsonPath('items.0.id', $ids[60])->assertJsonPath('items.9.id', $ids[69])->assertJsonPath('hasMore', true);
        $this->getJson($this->url($workspace, 'entries/'.$ids[149].'/neighbors').'?direction=next')->assertJsonCount(0, 'items')->assertJsonPath('hasMore', false);
        $other = $this->workspace('Other');
        $this->getJson($this->url($other, 'entries/'.$ids[70].'/neighbors').'?direction=next')->assertNotFound();
    }

    public function test_thumbnail_is_small_private_versioned_and_processing_status_survives_reload(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        $workspace = $this->workspace();
        $file = UploadedFile::fake()->image('landscape.jpg', 1600, 800);
        $response = $this->withHeader('Idempotency-Key', 'thumbnail-upload-00001')->postJson($this->url($workspace, 'uploads'), ['file' => $file])->assertCreated()->assertJsonPath('thumbnailState', 'queued')->assertJsonPath('thumbnailUrl', null);
        $entry = $response->json('id');
        Bus::assertDispatched(GenerateThumbnail::class);
        $job = Bus::dispatched(GenerateThumbnail::class)->first();
        $job->handle(app(ThumbnailProcessor::class));
        $details = $this->getJson($this->url($workspace, 'entries/'.$entry))->assertOk()->assertJsonPath('thumbnailState', 'ready')->assertJsonPath('width', 1600)->assertJsonPath('height', 800);
        $url = $details->json('thumbnailUrl');
        $this->assertNotNull($url);
        $thumbnail = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $bytes = $thumbnail->streamedContent();
        $info = getimagesizefromstring($bytes);
        $this->assertSame([400, 200], [$info[0], $info[1]]);
        $row = DB::table('fms_entries')->first();
        $this->assertSame('private', Storage::disk('studio-test')->getVisibility($row->thumbnail_key));
        $count = count(Storage::disk('studio-test')->allFiles());
        $job->handle(app(ThumbnailProcessor::class));
        $this->assertCount($count, Storage::disk('studio-test')->allFiles());
        $this->postJson($this->url($workspace, 'entries/status'), ['ids' => [$entry]])->assertJsonPath('items.0.thumbnailState', 'ready');
        $this->get($this->url($workspace, 'entries/'.$entry.'/thumbnail').'?v=wrong')->assertNotFound();
        $this->actingAs(new GenericUser(['id' => 'unrelated']));
        $this->get($url)->assertNotFound();
    }

    public function test_worker_rechecks_permission_and_never_generates_for_superseded_version(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        $workspace = $this->workspace();
        $this->withHeader('Idempotency-Key', 'thumbnail-upload-00001')->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->image('portrait.png', 80, 160)])->assertCreated();
        $job = Bus::dispatched(GenerateThumbnail::class)->first();
        DB::table('fms_memberships')->update(['role' => 'viewer']);
        try {
            $job->handle(app(ThumbnailProcessor::class));
            $this->fail('Revoked upload permission must prevent processing.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseHas('fms_renditions', ['state' => 'queued']);
        $this->assertCount(1, Storage::disk('studio-test')->allFiles());
        DB::table('fms_memberships')->update(['role' => 'owner']);
        DB::table('fms_entries')->update(['current_version_id' => (string) Str::ulid()]);
        $job->handle(app(ThumbnailProcessor::class));
        $this->assertCount(1, Storage::disk('studio-test')->allFiles());
        $this->assertDatabaseHas('fms_entries', ['thumbnail_state' => 'queued']);
    }
}
