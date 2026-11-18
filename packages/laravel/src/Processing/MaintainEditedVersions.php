<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Processing;

use Illuminate\Console\Command;

final class MaintainEditedVersions extends Command
{
    protected $signature = 'media-studio:edited-versions:maintain {--limit=25 : Number of pending retirements to process}';

    protected $description = 'Remove unreferenced versions superseded by an accepted image edit.';

    public function handle(EditedVersionRetention $retention): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            return self::INVALID;
        }

        $result = $retention->maintain($limit);
        $this->info(sprintf('Completed %d retirements, %d storage errors.', $result['removed'], $result['failed']));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
