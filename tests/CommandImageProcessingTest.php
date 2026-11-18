<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Processing\GenerateThumbnail;
use UseFlex\MediaStudio\Processing\ThumbnailProcessor;

final class CommandImageProcessingTest extends TestCase
{
    private function prepare(): string
    {
        Bus::fake([GenerateThumbnail::class]);
        config()->set('media-studio.processing.native.image_execution', 'command');
        $workspace = $this->workspace();
        foreach (['first', 'second'] as $name) {
            $this->withHeader('Idempotency-Key', 'command-render-test-'.$name)->postJson($this->url($workspace, 'uploads'), [
                'file' => UploadedFile::fake()->image($name.'.png', 80, 40),
            ])->assertCreated();
        }

        return $workspace;
    }

    public function test_command_mode_defers_images_and_scheduler_processes_bounded_batches_without_dispatch(): void
    {
        $this->prepare();
        Bus::assertNotDispatched(GenerateThumbnail::class);
        $this->assertSame(4, DB::table('fms_renditions')->where('state', 'queued')->count());
        $this->assertSame(0, Artisan::call('media-studio:renditions:reconcile', ['--images' => true, '--limit' => 1]));
        $this->assertSame(2, DB::table('fms_renditions')->where('state', 'ready')->count());
        $this->assertSame(2, DB::table('fms_renditions')->where('state', 'queued')->count());
        $this->assertSame(0, Artisan::call('media-studio:renditions:reconcile', ['--images' => true]));
        $this->assertSame(4, DB::table('fms_renditions')->where('state', 'ready')->count());
        Artisan::call('media-studio:renditions:reconcile', ['--images' => true]);
        $this->assertSame(4, DB::table('fms_renditions')->count());
        Bus::assertNotDispatched(GenerateThumbnail::class);
    }

    public function test_inline_image_preset_is_ready_before_larger_renditions_are_dispatched(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        config()->set('media-studio.processing.native.enabled', false);
        $workspaceId = $this->workspace();
        $uploaded = $this->withHeader('Idempotency-Key', 'inline-edit-thumbnail')
            ->postJson($this->url($workspaceId, 'uploads'), ['file' => UploadedFile::fake()->image('editor.png', 800, 400)])
            ->assertCreated()->json();
        $workspace = DB::table('fms_workspaces')->where('public_id', $workspaceId)->first();
        $entry = DB::table('fms_entries')->where('public_id', $uploaded['id'])->first();
        config()->set('media-studio.processing.native.enabled', true);
        config()->set('media-studio.processing.renditions.editor-card', ['width' => 96, 'height' => 96, 'format' => 'webp', 'quality' => 80]);

        app(ThumbnailProcessor::class)->schedule(
            $workspace, $entry, new Principal('user', 'member-uuid-001'), inlineImagePreset: 'editor-card',
        );

        $this->assertDatabaseHas('fms_renditions', [
            'version_id' => $entry->current_version_id, 'preset' => 'editor-card', 'state' => 'ready', 'width' => 96,
        ]);
        $this->assertDatabaseHas('fms_renditions', [
            'version_id' => $entry->current_version_id, 'preset' => 'preview-v1', 'state' => 'queued',
        ]);
        Bus::assertDispatchedOnce(GenerateThumbnail::class);
    }

    public function test_command_rechecks_permissions_and_rejects_ambiguous_or_invalid_limits(): void
    {
        $this->prepare();
        Gate::define('studio.files.process', fn () => false);
        $this->assertSame(0, Artisan::call('media-studio:renditions:reconcile', ['--images' => true]));
        $this->assertSame(4, DB::table('fms_renditions')->where('state', 'blocked')->count());
        $this->assertSame(2, Artisan::call('media-studio:renditions:reconcile', ['--images' => true, '--documents' => true]));
        $this->assertSame(2, Artisan::call('media-studio:renditions:reconcile', ['--images' => true, '--max-seconds' => 0]));
    }

    public function test_one_encoding_failure_does_not_prevent_other_batches_being_attempted(): void
    {
        $this->prepare();
        config()->set('media-studio.processing.native.driver', 'missing');
        $this->assertSame(1, Artisan::call('media-studio:renditions:reconcile', ['--images' => true]));
        $this->assertSame(4, DB::table('fms_renditions')->where('state', 'failed')->count());
        $this->assertSame(0, DB::table('fms_renditions')->where('state', 'ready')->count());
    }
}
