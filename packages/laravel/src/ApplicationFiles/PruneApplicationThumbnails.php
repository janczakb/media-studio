<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class PruneApplicationThumbnails extends Command
{
    protected $signature = 'media-studio:application-files:prune-thumbnails {--limit=100}';

    protected $description = 'Remove expired application thumbnails and release their reserved storage.';

    public function handle(ApplicationThumbnails $thumbnails): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            return self::INVALID;
        }
        if (Schema::hasTable('fms_application_thumbnails')) {
            $removed = $thumbnails->prune($limit);
            $this->info("Application thumbnail records removed: {$removed}.");
        }

        return self::SUCCESS;
    }
}
