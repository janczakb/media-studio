<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use UseFlex\MediaCore\Process\ProcessExecutor;
use UseFlex\MediaStudio\Audio\AudioWaveforms;
use UseFlex\MediaStudio\Identity\Principal;

final class AudioWaveformsTest extends TestCase
{
    public function test_worker_skips_audio_when_ffmpeg_is_missing(): void
    {
        config(['media-studio.processing.audio_waveforms.ffmpeg' => '/missing/media-studio-test/ffmpeg']);
        $this->mock(ProcessExecutor::class)->shouldNotReceive('writeFile');
        $waveforms = app(AudioWaveforms::class);

        self::assertFalse($waveforms->generate((string) Str::ulid(), (string) Str::ulid(), new Principal('user', 'member-uuid-001'), null));
    }

    public function test_audio_waveform_represents_sound_and_silence_without_a_thumbnail(): void
    {
        $ffmpeg = (new ExecutableFinder)->find('ffmpeg');
        if ($ffmpeg === null) {
            $this->markTestSkipped('FFmpeg is required for this integration test.');
        }
        config(['media-studio.processing.audio_waveforms.ffmpeg' => $ffmpeg]);
        $workspace = $this->workspace();

        $samples = '';
        for ($index = 0; $index < 16000; $index++) {
            $sample = $index < 8000 ? (int) (20000 * sin(2 * pi() * 440 * $index / 8000)) : 0;
            $samples .= pack('v', $sample & 0xFFFF);
        }
        $wav = 'RIFF'.pack('V', strlen($samples) + 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
        $entry = $this->withHeader('Idempotency-Key', (string) Str::ulid())->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->createWithContent('wave.wav', $wav),
        ])->assertCreated()->assertJsonPath('audioWaveform', null)->json();

        $waveforms = app(AudioWaveforms::class);
        $principal = new Principal('user', 'member-uuid-001');
        self::assertTrue($waveforms->generate($workspace, $entry['versionId'], $principal, null));
        self::assertFalse($waveforms->generate($workspace, $entry['versionId'], $principal, null));
        $waveform = $this->getJson($this->url($workspace))->assertOk()->assertJsonPath('items.0.thumbnailUrl', null)->json('items.0.audioWaveform');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $waveform);
        self::assertStringContainsString('f', substr($waveform, 0, 16));
        self::assertSame(str_repeat('0', 12), substr($waveform, -12));
        self::assertSame(0, DB::table('fms_renditions')->count());
        DB::table('fms_entries')->where('public_id', $entry['id'])->update(['audio_waveform_version' => (string) Str::ulid()]);
        $this->getJson($this->url($workspace))->assertOk()->assertJsonPath('items.0.audioWaveform', null);
    }
}
