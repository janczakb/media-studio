<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Audio;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class BackfillAudioWaveforms extends Command
{
    protected $signature = 'media-studio:audio:waveforms {workspace} {principal-type} {principal-id} {--after=0} {--limit=20}';

    protected $description = 'Generate one bounded page of audio waveforms without changing originals.';

    public function handle(AudioWaveforms $waveforms, WorkspaceAccess $access): int
    {
        $id = (string) $this->argument('workspace');
        $principal = new Principal((string) $this->argument('principal-type'), (string) $this->argument('principal-id'));
        $row = DB::table('fms_workspaces')->where('public_id', $id)->first();
        if (! $row) {
            return self::FAILURE;
        }
        $tenant = TenantIdentity::fromWorkspace($row);
        $workspace = $access->resolveForWorker($principal, $id, $tenant, write: true, operation: 'files.process');
        if ($waveforms->decoder() === null) {
            $this->warn('Audio waveform processing is disabled or FFmpeg is unavailable.');

            return self::SUCCESS;
        }

        $rows = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('media_type', 'audio')
            ->where('source_kind', 'managed')->whereNull('trashed_at')->whereNotNull('current_version_id')
            ->where('id', '>', max(0, (int) $this->option('after')))->orderBy('id')
            ->limit(max(1, min(100, (int) $this->option('limit'))))->get();
        $generated = 0;
        $failed = 0;
        foreach ($rows as $entry) {
            if (! $waveforms->eligible($entry)) {
                continue;
            }
            try {
                $generated += (int) $waveforms->generate($id, $entry->current_version_id, $principal, $tenant);
            } catch (Throwable $error) {
                report($error);
                $failed++;
            }
        }
        $this->info(sprintf('Generated %d, failed %d. Next cursor: %d.', $generated, $failed, $rows->last()->id ?? (int) $this->option('after')));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
