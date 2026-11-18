<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Intelligence;

use Illuminate\Console\Command;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class AnalyzeImageCommand extends Command
{
    protected $signature = 'media-studio:analyze-image {workspace} {entry} {actor-type} {actor-id} {--video}';

    protected $description = 'Analyze one prepared image derivative using explicitly configured AI';

    public function handle(AnalyzeImage $analyzer): int
    {
        try {
            $type = (string) $this->argument('actor-type');
            $id = (string) $this->argument('actor-id');
            if (! array_key_exists($type, (array) config('media-studio.principals')) || $id === '' || strlen($id) > 191) {
                return self::INVALID;
            }
            $actor = new Principal($type, $id);
            $this->line(($this->option('video') ? app(AnalyzeVideo::class) : $analyzer)->run($actor, (string) $this->argument('workspace'), (string) $this->argument('entry'), app(WorkspaceAccess::class)->tenant($actor)));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Image analysis unavailable or access denied.');

            return self::FAILURE;
        }
    }
}
