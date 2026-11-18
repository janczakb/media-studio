<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use UseFlex\MediaStudio\Processing\EditedVersionRetention;

final class EditedVersionRetentionTest extends TestCase
{
    public function test_history_remains_enabled_by_default(): void
    {
        [, , $previous] = $this->replaceImage();

        $this->artisan('media-studio:edited-versions:maintain')->assertSuccessful();
        $this->assertDatabaseCount('fms_edited_version_retirements', 0);
        Storage::disk($previous->disk)->assertExists($previous->object_key);
    }

    public function test_manual_mode_removes_the_superseded_file_without_enabling_global_cleanup(): void
    {
        [, $entry, $previous, $current] = $this->replaceImage(manual: true);
        $disk = Storage::disk($previous->disk);
        $disk->assertExists($previous->object_key);

        $this->assertTrue(app(EditedVersionRetention::class)->removeNow($previous->id));

        $disk->assertMissing($previous->object_key);
        $disk->assertExists($current['object_key']);
        $this->assertDatabaseHas('fms_entries', ['id' => $entry->id, 'current_version_id' => $current['id'], 'trashed_at' => null]);
        $this->assertDatabaseCount('fms_edited_version_retirements', 0);
    }

    public function test_retirement_waits_for_a_confirmed_replacement_then_removes_the_old_bytes(): void
    {
        config()->set('media-studio.edited_versions.keep_history', false);
        [$workspace, $entry, $previous, $current] = $this->replaceImage();
        $disk = Storage::disk($previous->disk);
        $editedBytes = $disk->get($current['object_key']);
        $disk->delete($current['object_key']);

        $this->artisan('media-studio:edited-versions:maintain')->assertSuccessful();
        $disk->assertExists($previous->object_key);
        $disk->put($current['object_key'], $editedBytes);

        $this->travel(61)->minutes();
        $this->artisan('media-studio:edited-versions:maintain')->assertSuccessful();
        $disk->assertMissing($previous->object_key);
        $disk->assertExists($current['object_key']);
        $this->assertDatabaseMissing('fms_asset_versions', ['id' => $previous->id]);
        $this->assertDatabaseCount('fms_edited_version_retirements', 0);
        $this->get($this->url($workspace->public_id, 'entries/'.$entry->public_id.'/versions/'.$previous->id.'/content'))->assertNotFound();
        $this->get($this->url($workspace->public_id, 'entries/'.$entry->public_id.'/versions/'.$current['id'].'/content'))->assertOk();
    }

    public function test_registered_pins_preserve_a_version_until_the_reference_is_removed(): void
    {
        [$workspace, $entry, $previous] = $this->replaceImage(manual: true);
        $usage = DB::table('fms_usages')->insertGetId([
            'workspace_id' => $workspace->id, 'entry_id' => $entry->id, 'version_id' => $previous->id,
            'owner_type' => 'test', 'owner_id' => '1', 'owner_key' => hash('sha256', 'test:1'),
            'attribute' => 'image', 'slot' => 'main',
        ]);

        $this->assertFalse(app(EditedVersionRetention::class)->removeNow($previous->id));
        $this->assertDatabaseHas('fms_asset_versions', ['id' => $previous->id]);
        Storage::disk($previous->disk)->assertExists($previous->object_key);

        DB::table('fms_usages')->where('id', $usage)->delete();
        $this->travel(61)->minutes();
        $this->artisan('media-studio:edited-versions:maintain')->assertSuccessful();
        Storage::disk($previous->disk)->assertMissing($previous->object_key);
    }

    public function test_failed_storage_delete_retries_after_the_version_has_been_detached(): void
    {
        config()->set('media-studio.edited_versions.keep_history', false);
        [, , $previous, $current] = $this->replaceImage();
        $disk = Storage::disk($previous->disk);
        $failingDisk = Mockery::mock($disk);
        $failingDisk->shouldReceive('delete')->with($previous->object_key)->once()->andThrow(new RuntimeException('Simulated unavailable disk.'));
        Storage::set($previous->disk, $failingDisk);

        $this->artisan('media-studio:edited-versions:maintain')->assertFailed();
        $disk->assertExists($previous->object_key);
        $this->assertDatabaseMissing('fms_asset_versions', ['id' => $previous->id]);
        $this->assertDatabaseHas('fms_edited_version_retirements', ['version_id' => $previous->id]);

        Storage::set($previous->disk, $disk);
        config()->set('media-studio.edited_versions.keep_history', true);
        $this->travel(6)->minutes();
        $this->artisan('media-studio:edited-versions:maintain')->assertSuccessful();
        $disk->assertMissing($previous->object_key);
        $disk->assertExists($current['object_key']);
        $this->assertDatabaseCount('fms_edited_version_retirements', 0);
    }

    private function replaceImage(bool $manual = false): array
    {
        $id = $this->workspace();
        $uploaded = $this->withHeader('Idempotency-Key', 'edited-versions-original')
            ->postJson($this->url($id, 'uploads'), ['file' => UploadedFile::fake()->image('original.png', 20, 30)])
            ->assertCreated()->json();
        $workspace = DB::table('fms_workspaces')->where('public_id', $id)->first();
        $entry = DB::table('fms_entries')->where('public_id', $uploaded['id'])->first();
        $previous = DB::table('fms_asset_versions')->where('id', $entry->current_version_id)->first();
        $next = (string) Str::ulid();
        $current = [
            ...((array) $previous),
            'id' => $next,
            'object_key' => dirname($previous->object_key).'/'.$next,
        ];
        Storage::disk($previous->disk)->copy($previous->object_key, $current['object_key']);

        DB::transaction(function () use ($workspace, $entry, $previous, $current, $manual): void {
            DB::table('fms_asset_versions')->insert($current);
            DB::table('fms_entries')->where('id', $entry->id)->update([
                'current_version_id' => $current['id'], 'thumbnail_version_id' => null, 'preview_version_id' => null,
            ]);
            app(EditedVersionRetention::class)->retire($workspace, $entry, $previous, manual: $manual);
        });

        return [$workspace, $entry, $previous, $current];
    }
}
