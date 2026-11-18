<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

use Illuminate\Console\Command;
use UseFlex\MediaStudio\Identity\Principal;

final class WarmApplicationThumbnails extends Command
{
    protected $signature = 'media-studio:application-files:warm-thumbnails {source} {principal-type} {principal-id}
        {--after=0 : Last visited catalog row ID}
        {--limit=16 : Maximum catalog rows to visit, 1–16}';

    protected $description = 'Generate one bounded thumbnail batch explicitly, including on servers without a queue worker.';

    public function handle(ApplicationCatalog $catalog, ApplicationThumbnails $thumbnails): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 16]]);
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($limit === false || $after === false) {
            return self::INVALID;
        }
        $profile = ApplicationThumbnailProfile::configured();
        if ($profile === null) {
            $this->error('Enable application thumbnails and publish their optional migration first.');

            return self::FAILURE;
        }
        $principal = new Principal((string) $this->argument('principal-type'), (string) $this->argument('principal-id'));
        $source = $catalog->source($principal, (string) $this->argument('source'), true);
        $batch = $catalog->query($source)->where('id', '>', $after)->orderBy('id')->limit($limit)->get()->all();
        $rows = $catalog->visible($source, $principal, $batch);
        $sources = [$source->key => $source];
        $keys = $thumbnails->keys($profile, $rows, $sources);
        $thumbnails->request($profile, $keys, $rows, $sources, $principal, runInline: true);
        $states = $thumbnails->states($keys);
        $this->line(json_encode([
            'visited' => count($batch), 'ready' => count(array_filter($states, static fn (string $state): bool => $state === 'ready')),
            'nextAfter' => count($batch) === $limit ? end($batch)->id : null,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
