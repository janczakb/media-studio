<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

use Illuminate\Console\Command;
use UseFlex\MediaStudio\Identity\Principal;

final class IndexApplicationFiles extends Command
{
    protected $signature = 'media-studio:application-files:index {source} {principal-type} {principal-id}
        {--path=* : Explicit disk object paths, maximum 120 per invocation}
        {--after= : Last visited model or Spatie primary key}
        {--limit=60 : Model or Spatie batch size, 1–120}';

    protected $description = 'Index a bounded read-only application source without assigning a workspace or copying originals.';

    public function handle(ApplicationCatalog $catalog): int
    {
        $principal = new Principal((string) $this->argument('principal-type'), (string) $this->argument('principal-id'));
        $key = (string) $this->argument('source');
        $source = $catalog->source($principal, $key, true);
        if ($source->config['type'] === 'disk') {
            $paths = $this->option('path');
            if ($paths === [] || count($paths) > 120) {
                $this->error('Supply between 1 and 120 explicit object paths. No filesystem or bucket is scanned.');

                return self::INVALID;
            }
            $result = ['indexed' => $catalog->indexDisk($principal, $key, $paths)];
        } else {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
            if ($limit === false) {
                return self::INVALID;
            }
            $result = $source->config['type'] === 'model'
                ? $catalog->indexModels($principal, $key, $this->option('after'), $limit)
                : $catalog->indexSpatie($principal, $key, $this->option('after'), $limit);
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
