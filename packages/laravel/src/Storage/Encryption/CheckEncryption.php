<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use Illuminate\Console\Command;

final class CheckEncryption extends Command
{
    protected $signature = 'media-studio:encryption:check {--json : Return a machine-readable report}';

    protected $description = 'Report configured encryption scope and unresolved protection boundaries without scanning storage';

    public function handle(EncryptionReadiness $readiness): int
    {
        $report = $readiness->report();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->line('Encryption mode: '.$report['mode']);
            $this->warn('Full deployment protection has not been independently verified.');
            foreach ($report['unverifiedBoundaries'] as $boundary) {
                $this->line('- '.$boundary);
            }
        }

        return self::SUCCESS;
    }
}
