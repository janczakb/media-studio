<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sources;

use Illuminate\Console\Command;

final class SynchronizeSources extends Command
{
    protected $signature = 'media-studio:sources:sync {target? : One configured synchronization target} {--limit=60 : Records per page, 1–120} {--pages=10 : Maximum pages per target, 1–100} {--max-seconds=20 : Stop admitting work after this budget}';

    protected $description = 'Synchronize external source projections in bounded, resumable pages without deleting originals.';

    public function handle(SourceSynchronizer $synchronizer): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
        $pages = filter_var($this->option('pages'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $seconds = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 300]]);
        if ($limit === false || $pages === false || $seconds === false) {
            $this->error('Invalid synchronization limits.');

            return self::INVALID;
        }
        $targets = config('media-studio.sources.sync.targets', []);
        if (! is_array($targets) || count($targets) > 100) {
            $this->error('Configure at most 100 synchronization targets per invocation.');

            return self::INVALID;
        }
        $keys = $this->argument('target') === null ? array_keys(array_filter($targets, fn ($target): bool => is_array($target) && ($target['enabled'] ?? true) === true)) : [(string) $this->argument('target')];
        $deadline = hrtime(true) + $seconds * 1000000000;
        $failed = false;
        $done = [];
        for ($page = 0; $page < $pages && hrtime(true) < $deadline; $page++) {
            foreach ($keys as $key) {
                if (isset($done[$key]) || hrtime(true) >= $deadline) {
                    continue;
                }
                try {
                    $result = $synchronizer->run((string) $key, $limit);
                    $this->line($key.': '.$result['state'].' ('.$result['checked'].')');
                    if (in_array($result['state'], ['waiting', 'complete'], true)) {
                        $done[$key] = true;
                    }
                } catch (\Throwable $error) {
                    // Storage exception messages can contain private object addresses. Keep scheduler output minimal.
                    $this->error($key.': synchronization failed; index checkpoint retained.');
                    report($error);
                    $failed = true;
                    $done[$key] = true;
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
