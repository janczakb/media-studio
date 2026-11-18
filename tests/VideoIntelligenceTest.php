<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Intelligence\AnalyzeVideo;

final class VideoIntelligenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! is_executable('/opt/homebrew/bin/ffmpeg') || ! is_executable('/opt/homebrew/bin/ffprobe')) {
            $this->markTestSkipped('Real FFmpeg required.');
        }
        foreach (glob(__DIR__.'/../packages/laravel/database/intelligence-migrations/*.php') as $path) {
            (require $path)->up();
        }
        config(['media-studio.intelligence.enabled' => true, 'media-studio.intelligence.videos' => true, 'media-studio.intelligence.provider' => 'google_vision', 'media-studio.intelligence.providers.google_vision.key' => 'test-key', 'media-studio.intelligence.video.max_frames' => 2, 'media-studio.intelligence.video.ffmpeg' => '/opt/homebrew/bin/ffmpeg', 'media-studio.intelligence.video.ffprobe' => '/opt/homebrew/bin/ffprobe']);
        Http::preventStrayRequests();
    }

    private function video(): array
    {
        $file = tmpfile();
        try {
            $path = stream_get_meta_data($file)['uri'];
            (new Process(['/opt/homebrew/bin/ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=red:s=64x48:r=10', '-t', '1', '-c:v', 'libx264', '-threads', '1', '-f', 'mp4', $path]))->mustRun();
            $bytes = file_get_contents($path);
        } finally {
            fclose($file);
        }
        $workspace = $this->workspace();
        $id = (string) Str::ulid();
        $version = (string) Str::ulid();
        Storage::disk('studio-test')->put('sample.mp4', $bytes);
        $entry = DB::table('fms_entries')->insertGetId(['workspace_id' => DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'), 'public_id' => $id, 'kind' => 'file', 'sort_group' => 1, 'name' => 'Video', 'normalized_name' => 'video', 'media_type' => 'video', 'source_kind' => 'managed', 'current_version_id' => $version, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fms_asset_versions')->insert(['id' => $version, 'entry_id' => $entry, 'disk' => 'studio-test', 'object_key' => 'sample.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'created_at' => now()]);

        return [$workspace, $id, $entry, $version];
    }

    public function test_real_frame_sampling_is_bounded_deduplicated_and_idempotent(): void
    {
        [$workspace, $id] = $this->video();
        Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['labelAnnotations' => [['description' => 'Red', 'score' => .9]]]]])]);
        $analyzer = app(AnalyzeVideo::class);
        $this->assertSame('ready_partial', $analyzer->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null));
        $this->assertSame(2, DB::table('fms_analysis_segments')->count());
        $this->assertSame(1, DB::table('fms_ai_labels')->count());
        $this->assertSame('ready_partial', $analyzer->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null));
        Http::assertSentCount(1);
        $this->assertSame(['sample.mp4'], Storage::disk('studio-test')->allFiles());
    }

    public function test_video_size_limit_skips_before_provider_and_storage_reads(): void
    {
        [$workspace, $id] = $this->video();
        config(['media-studio.intelligence.video.max_source_bytes' => 1]);
        Http::fake();
        $this->assertSame('skipped_size', app(AnalyzeVideo::class)->run(new Principal('user', 'member-uuid-001'), $workspace, $id, null));
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('fms_image_analyses')->count());
    }
}
