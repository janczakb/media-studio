<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Explicit bounded maintenance: safe to schedule; never scans storage or reads originals. */
final class IndexSearch extends Command
{
    protected $signature = 'media-studio:search-index {--after=0} {--limit=120} {--configure}';

    protected $description = 'Submit a bounded page of Studio file names to the optional search index';

    public function handle(Meilisearch $search): int
    {
        if (config('media-studio.search.enabled') !== true) {
            $this->error('Enable and configure search first.');

            return self::FAILURE;
        }
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($after === false || $after < 0 || $limit === false || $limit < 1 || $limit > 120) {
            $this->error('Use after >= 0 and limit between 1 and 120.');

            return self::FAILURE;
        }
        $lock = Cache::store(config('media-studio.search.cache_store'))->lock('fms:search:'.hash('sha256', (string) config('media-studio.search.meilisearch.host').$search->index()), 60);
        if (! $lock->get()) {
            $this->error('Another index submission is running.');

            return self::FAILURE;
        }
        try {
            if ($this->option('configure')) {
                $this->line('Settings task: '.$search->configure());
            }
            $rows = DB::table('fms_entries as e')->join('fms_workspaces as w', 'w.id', '=', 'e.workspace_id')->where('e.id', '>', $after)->orderBy('e.id')->limit($limit)->get(['e.id', 'e.public_id', 'e.name', 'e.revision', 'e.current_version_id', 'e.trashed_at', 'w.public_id as workspace']);
            if ($rows->isEmpty()) {
                $this->line('No more entries.');

                return self::SUCCESS;
            }
            $content = app(ContentProjection::class)->forEntries($rows, true);
            $ai = app(AiProjection::class)->forEntries($rows);
            $documents = $rows->map(static function ($row) use ($content, $ai): array {
                $projection = $content[$row->id] ?? null;
                $current = ContentProjection::current($projection, $row);

                return ['id' => $row->public_id, 'workspace' => $row->workspace, 'name' => $row->trashed_at === null ? $row->name : '', 'revision' => (int) $row->revision, 'version' => $row->current_version_id, 'content' => $current ? $projection->content : '', 'contentHash' => $current ? $projection->fingerprint : '', 'ai' => $ai[$row->id]['text'] ?? '', 'aiHash' => $ai[$row->id]['hash'] ?? ''];
            })->all();
            $this->line('Documents task: '.$search->publish($documents));
            $this->line('Next after: '.$rows->last()->id);
            $this->line('Submission acknowledged; inspect the Meilisearch task before treating indexing as complete.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
