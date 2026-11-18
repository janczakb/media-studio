<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Audio;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;
use UseFlex\MediaCore\Process\ProcessExecutor;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class AudioWaveforms
{
    private const BARS = 32;

    private const RATE = 100;

    public function eligible(object $entry): bool
    {
        return config('media-studio.processing.audio_waveforms.enabled', true) === true
            && $entry->media_type === 'audio' && ($entry->source_kind ?? 'managed') === 'managed'
            && $entry->current_version_id !== null && ($entry->audio_waveform_version ?? null) !== $entry->current_version_id
            && $entry->size_bytes > 0 && $entry->size_bytes <= $this->maxBytes();
    }

    public function schedule(object $workspace, object $entry, Principal $principal): void
    {
        if (! $this->eligible($entry)) {
            return;
        }
        $connection = config('media-studio.processing.audio_waveforms.connection') ?? config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') === 'sync') {
            return;
        }
        $tenant = TenantIdentity::fromWorkspace($workspace);
        $job = new GenerateAudioWaveform($workspace->public_id, $entry->current_version_id, $principal, $tenant);
        $job->onConnection($connection)->onQueue(config('media-studio.processing.audio_waveforms.queue', 'audio-waveforms'));
        DB::afterCommit(static function () use ($job): void {
            try {
                app(Dispatcher::class)->dispatch($job);
            } catch (Throwable $error) {
                report($error);
            }
        });
    }

    public function decoder(): ?string
    {
        if (config('media-studio.processing.audio_waveforms.enabled', true) !== true) {
            return null;
        }

        $binary = config('media-studio.processing.audio_waveforms.ffmpeg') ?: 'ffmpeg';
        if (str_contains($binary, '/') || str_contains($binary, chr(92))) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        return (new ExecutableFinder)->find($binary);
    }

    public function generate(string $workspaceId, string $versionId, Principal $principal, ?TenantIdentity $tenant): bool
    {
        // The worker may run on a different host than the web process.
        $binary = $this->decoder();
        if ($binary === null) {
            return false;
        }

        return Cache::lock('fms-audio-waveform:'.$versionId, 150)->get(function () use ($workspaceId, $versionId, $principal, $tenant, $binary): bool {
            $workspace = app(WorkspaceAccess::class)->resolveForWorker($principal, $workspaceId, $tenant, write: true, operation: 'files.process');
            $entry = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('current_version_id', $versionId)->whereNull('trashed_at')->first();
            if (! $entry || ! $this->eligible($entry)) {
                return false;
            }
            $version = DB::table('fms_asset_versions')->where('id', $versionId)->where('entry_id', $entry->id)->first();
            if (! $version || $version->size_bytes > $this->maxBytes()) {
                return false;
            }
            $samples = $this->read($version, $binary);
            app(WorkspaceAccess::class)->resolveForWorker($principal, $workspaceId, $tenant, write: true, operation: 'files.process');

            return DB::table('fms_entries')->where('id', $entry->id)->where('workspace_id', $workspace->id)
                ->where('current_version_id', $versionId)->whereNull('trashed_at')
                ->update(['audio_waveform' => $samples, 'audio_waveform_version' => $versionId]) === 1;
        }) === true;
    }

    private function maxBytes(): int
    {
        return max(1024, min(268435456, (int) config('media-studio.processing.audio_waveforms.max_source_bytes', 134217728)));
    }

    private function read(object $version, string $binary): string
    {
        $input = tmpfile();
        $output = tmpfile();
        $source = null;
        try {
            if (! is_resource($input) || ! is_resource($output)) {
                throw new RuntimeException('audio_waveform_temp_failed');
            }
            $source = Storage::disk($version->disk)->readStream($version->object_key);
            $path = stream_get_meta_data($input)['uri'];
            if (! is_resource($source) || stream_copy_to_stream($source, $input, $this->maxBytes() + 1) !== (int) $version->size_bytes
                || ($version->sha256 !== null && ! hash_equals($version->sha256, hash_file('sha256', $path)))) {
                throw new RuntimeException('audio_waveform_source_changed');
            }

            $maxSeconds = max(1, min(14400, (int) config('media-studio.processing.audio_waveforms.max_seconds', 7200)));
            $outputPath = stream_get_meta_data($output)['uri'];
            app(ProcessExecutor::class)->writeFile([
                $binary, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'mp3,wav,flac,ogg,mov,aac,matroska,webm,aiff,asf,amr,ape,ac3,au,caf,mpc,mpc8,tta,wv',
                '-threads', '1', '-i', $path, '-map', '0:a:0', '-vn', '-sn', '-dn',
                '-filter_threads', '1', '-af', 'aformat=channel_layouts=mono,aresample=8000,aeval=abs(val(0)),lowpass=f=22,aresample='.self::RATE,
                '-t', (string) ($maxSeconds + 1), '-c:a', 'pcm_u8', '-f', 'u8', $outputPath,
            ], 45, $outputPath, ($maxSeconds + 1) * self::RATE, 'audio_waveform_decode_failed');

            clearstatcache(true, $outputPath);
            $length = (int) filesize($outputPath);
            if ($length < 1 || $length > $maxSeconds * self::RATE) {
                throw new RuntimeException('audio_waveform_duration_limit');
            }

            return $this->bars($output, $length);
        } finally {
            foreach ([$input, $output, $source] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    /** Reduce the envelope in bounded chunks; a bar is one hexadecimal digit on the wire. */
    private function bars(mixed $stream, int $length): string
    {
        rewind($stream);
        $peaks = array_fill(0, self::BARS, 0);
        $offset = 0;
        while ($offset < $length) {
            $chunk = fread($stream, min(8192, $length - $offset));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('audio_waveform_read_failed');
            }
            $size = strlen($chunk);
            for ($i = 0; $i < $size; $i++, $offset++) {
                $bar = min(self::BARS - 1, intdiv($offset * self::BARS, $length));
                $peaks[$bar] = max($peaks[$bar], ord($chunk[$i]) - 128);
            }
        }
        $maximum = max(3, max($peaks));

        return implode('', array_map(static fn (int $peak): string => dechex((int) round(15 * $peak / $maximum)), $peaks));
    }
}
