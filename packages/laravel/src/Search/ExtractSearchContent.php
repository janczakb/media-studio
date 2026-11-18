<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use Illuminate\Console\Command;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class ExtractSearchContent extends Command
{
    protected $signature = 'media-studio:search-extract {workspace} {entry} {actor-type} {actor-id}';

    protected $description = 'Extract bounded text from one managed file under the configured actor permissions';

    public function handle(ExtractTextContent $extract): int
    {
        try {
            $type = (string) $this->argument('actor-type');
            $id = (string) $this->argument('actor-id');
            if (! array_key_exists($type, (array) config('media-studio.principals')) || $id === '' || strlen($id) > 191) {
                return self::INVALID;
            }
            $principal = new Principal($type, $id);
            $result = $extract->run($principal, (string) $this->argument('workspace'), (string) $this->argument('entry'), app(WorkspaceAccess::class)->tenant($principal));
            $this->line($result);

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Text extraction unavailable or access denied.');

            return self::FAILURE;
        }
    }
}
