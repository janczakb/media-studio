<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaStudio\Video\FfmpegTrimProcessor;
use UseFlex\MediaStudio\Video\TrimVideoJob;
use UseFlex\MediaStudio\Video\VideoEdits;
use UseFlex\MediaStudio\Video\VideoTrimProcessor;

final class VideoEditsTest extends TestCase
{
    private function configure(): void
    {
        config(['media-studio.video_editing.min_selection_seconds' => .1, 'media-studio.video_editing.enabled' => true, 'media-studio.video_editing.mode' => 'sync', 'media-studio.video_editing.ffmpeg' => '/opt/homebrew/bin/ffmpeg', 'media-studio.video_editing.ffprobe' => '/opt/homebrew/bin/ffprobe']);
    }

    private function clip(string $workspace): array
    {
        return $this->withHeader('Idempotency-Key', 'video-fixture-upload-12345')->postJson($this->url($workspace, 'uploads'), ['file' => new UploadedFile(__DIR__.'/fixtures/video-loading.webm', 'clip.webm', 'video/webm', null, true)])->assertCreated()->json();
    }

    public function test_minimum_selection_is_advertised_and_enforced_before_dispatch(): void
    {
        $this->configure();
        config([
            'media-studio.video_editing.min_selection_seconds' => 3,
            'media-studio.video_editing.processor' => UnavailableOutputProcessor::class,
        ]);
        $workspace = $this->workspace();
        $entry = $this->clip($workspace);
        $path = $this->url($workspace, 'entries/'.$entry['id'].'/video-edit');

        $this->getJson($path)->assertOk()->assertJsonPath('minSelection', 3);
        $this->postJson($path, ['revision' => 1, 'start' => 0, 'end' => .7, 'requestId' => 'request-video-test-1234'])
            ->assertUnprocessable()->assertJsonPath('message', 'The selected clip is too short.');
        $this->assertDatabaseCount('fms_video_edits', 0);
    }

    public function test_disabled_configuration_hides_trim_and_rejects_execution(): void
    {
        $w = $this->workspace();
        $entry = $this->clip($w);
        $path = $this->url($w, 'entries/'.$entry['id'].'/video-edit');
        $this->getJson($path)->assertOk()->assertJsonPath('enabled', false);
        $this->postJson($path, ['revision' => 1, 'start' => 0, 'end' => .7, 'requestId' => 'request-video-test-1234'])->assertUnprocessable();
        $this->assertDatabaseCount('fms_video_edits', 0);
    }

    public function test_native_trim_verifies_output_keeps_previous_version_and_retries_idempotently(): void
    {
        $this->configure();
        if (! app(FfmpegTrimProcessor::class)->available()) {
            $this->markTestSkipped('Native FFmpeg with H264/AAC is unavailable.');
        }
        $w = $this->workspace();
        $entry = $this->clip($w);
        $path = $this->url($w, 'entries/'.$entry['id'].'/video-edit');
        $original = DB::table('fms_asset_versions')->first();
        $originalBytes = Storage::disk('studio-test')->get($original->object_key);
        $input = ['revision' => 1, 'start' => .2, 'end' => .8, 'requestId' => 'request-video-test-1234'];
        $id = $this->postJson($path, $input)->assertAccepted()->assertJsonPath('state', 'ready')->json('id');
        $this->postJson($path, $input)->assertAccepted()->assertJsonPath('id', $id)->assertJsonPath('state', 'ready');
        $this->assertDatabaseCount('fms_asset_versions', 2);
        $this->assertDatabaseCount('fms_video_edits', 1);
        $this->getJson($this->url($w, 'entries/'.$entry['id']))->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('mimeType', 'video/mp4')->assertJsonPath('name', 'clip.mp4');
        $this->assertSame($originalBytes, Storage::disk('studio-test')->get($original->object_key));
        $version = DB::table('fms_asset_versions')->where('id', $id)->first();
        $tmp = tmpfile();
        fwrite($tmp, Storage::disk('studio-test')->get($version->object_key));
        try {
            $info = app(FfmpegTrimProcessor::class)->inspect(stream_get_meta_data($tmp)['uri']);
            $this->assertEqualsWithDelta(.6, $info['duration'], .15);
            $this->assertSame(64, $info['width']);
        } finally {
            fclose($tmp);
        }
    }

    public function test_worker_rechecks_permissions_and_preserves_original_on_failure(): void
    {
        $this->configure();
        config(['media-studio.video_editing.processor' => UnavailableOutputProcessor::class, 'media-studio.video_editing.mode' => 'queue', 'media-studio.video_editing.connection' => 'database', 'queue.connections.database.driver' => 'database']);
        Bus::fake();
        $w = $this->workspace();
        $entry = $this->clip($w);
        $path = $this->url($w, 'entries/'.$entry['id'].'/video-edit');
        $id = $this->postJson($path, ['revision' => 1, 'start' => 0, 'end' => .7, 'requestId' => 'request-video-test-1234'])->assertAccepted()->assertJsonPath('state', 'queued')->json('id');
        Bus::assertDispatched(TrimVideoJob::class);
        Gate::define('studio.files.process', static fn () => false);
        app(VideoEdits::class)->run($id);
        $this->assertDatabaseHas('fms_video_edits', ['id' => $id, 'state' => 'failed']);
        $this->assertDatabaseCount('fms_asset_versions', 1);
        $this->getJson($this->url($w, 'entries/'.$entry['id']))->assertOk()->assertJsonPath('revision', 1);
    }

    public function test_invalid_range_stale_revision_and_read_only_permissions_are_rejected(): void
    {
        $this->configure();
        config(['media-studio.video_editing.processor' => UnavailableOutputProcessor::class]);
        $w = $this->workspace();
        $entry = $this->clip($w);
        $path = $this->url($w, 'entries/'.$entry['id'].'/video-edit');
        $this->postJson($path, ['revision' => 1, 'start' => 2, 'end' => 1, 'requestId' => 'request-video-test-1234'])->assertUnprocessable();
        $this->postJson($path, ['revision' => 99, 'start' => 0, 'end' => .7, 'requestId' => 'request-video-test-1234'])->assertConflict();
        Gate::define('studio.files.update', static fn () => false);
        $this->getJson($path)->assertOk()->assertJsonPath('enabled', false);
        $this->postJson($path, ['revision' => 1, 'start' => 0, 'end' => .7, 'requestId' => 'request-video-test-1234'])->assertUnprocessable();
        $this->assertDatabaseCount('fms_video_edits', 0);
    }
}
final class UnavailableOutputProcessor implements VideoTrimProcessor
{
    public function available(): bool
    {
        return true;
    }

    public function inspect(string $localFile): array
    {
        return ['duration' => 1.0, 'width' => 64, 'height' => 48];
    }

    public function trim(string $source, string $destination, float $start, float $end): void
    {
        throw new \RuntimeException('fixture_failure');
    }
}
