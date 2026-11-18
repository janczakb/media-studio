<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use Illuminate\Console\Command;

final class SynchronizeSearch extends Command
{
    protected $signature = 'media-studio:search-sync {--limit=120} {--pages=10} {--max-seconds=20}';

    protected $description = 'Reconcile a bounded page of the optional search index with durable checkpoints';

    public function handle(SearchSynchronizer $sync): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
        $pages = filter_var($this->option('pages'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $seconds = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 300]]);
        if ($limit === false || $pages === false || $seconds === false) {
            return self::INVALID;
        }
        $deadline = hrtime(true) + $seconds * 1000000000;
        try {
            for ($page = 0; $page < $pages && hrtime(true) < $deadline; $page++) {
                $result = $sync->run($limit);
                $this->line($result['state'].' ('.$result['checked'].')');
                if (in_array($result['state'], ['waiting', 'submitted', 'complete', 'busy', 'failed'], true)) {
                    return $result['state'] === 'failed' ? self::FAILURE : self::SUCCESS;
                }
            }
        } catch (\Throwable) {
            $this->error('Search synchronization unavailable; checkpoint retained.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
