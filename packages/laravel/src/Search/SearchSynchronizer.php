<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Bounded reconciliation of SQL metadata. No source I/O; receipts require successful engine tasks. */
final class SearchSynchronizer
{
    public function run(int $limit = 120): array
    {
        abort_unless(config('media-studio.search.enabled') === true, 404);
        if ($limit < 1 || $limit > 120) {
            throw new \InvalidArgumentException('Use a search batch size between 1 and 120.');
        }
        $engine = app(Meilisearch::class);
        $id = hash('sha256', (string) config('media-studio.search.meilisearch.host').'/'.$engine->index());
        // All index writers use the same shared lock. HTTP has a10-second timeout; lease60s.
        $lock = Cache::store(config('media-studio.search.cache_store'))->lock('fms:search:'.hash('sha256', (string) config('media-studio.search.meilisearch.host').$engine->index()), 60);
        if (! $lock->get()) {
            return ['state' => 'busy', 'checked' => 0];
        }
        try {
            DB::table('fms_search_syncs')->insertOrIgnore(['id' => $id]);
            $state = DB::table('fms_search_syncs')->where('id', $id)->first();
            if ($state->task_id !== null) {
                return $this->acknowledge($engine, $state);
            }

            return $state->phase === 'scan' ? $this->scan($engine, $state, $limit) : $this->sweep($engine, $state, $limit);
        } finally {
            $lock->release();
        }
    }

    private function scan(Meilisearch $engine, object $state, int $limit): array
    {
        $rows = DB::table('fms_entries as e')->join('fms_workspaces as w', 'w.id', '=', 'e.workspace_id')->where('e.id', '>', $state->after_id)->orderBy('e.id')->limit($limit)->get(['e.id', 'e.public_id', 'e.name', 'e.revision', 'e.current_version_id', 'e.trashed_at', 'w.public_id as workspace']);
        if ($rows->isEmpty()) {
            DB::table('fms_search_syncs')->where('id', $state->id)->update(['phase' => 'sweep', 'after_id' => 0]);

            return ['state' => 'sweeping', 'checked' => 0];
        }
        $known = DB::table('fms_search_receipts')->where('sync_id', $state->id)->whereIn('entry_id', $rows->pluck('id'))->get()->keyBy('entry_id');
        $content = app(ContentProjection::class)->forEntries($rows, true);
        $ai = app(AiProjection::class)->forEntries($rows);
        $documents = [];
        $receipts = [];
        foreach ($rows as $row) {
            // Trash entries use blank names; authoritative query blocks all their results immediately.
            $document = ['id' => $row->public_id, 'workspace' => $row->workspace, 'name' => $row->trashed_at === null ? $row->name : '', 'revision' => (int) $row->revision, 'version' => $row->current_version_id];
            $projection = $content[$row->id] ?? null;
            $current = ContentProjection::current($projection, $row);
            $document['content'] = $current ? $projection->content : '';
            $document['contentHash'] = $current ? $projection->fingerprint : '';
            $document['ai'] = $ai[$row->id]['text'] ?? '';
            $document['aiHash'] = $ai[$row->id]['hash'] ?? '';
            $fingerprint = hash('sha256', json_encode($document, JSON_THROW_ON_ERROR));
            if (($known->get($row->id)->fingerprint ?? null) !== $fingerprint) {
                $documents[] = $document;
                $receipts[] = ['sync_id' => $state->id, 'entry_id' => (int) $row->id, 'public_id' => $row->public_id, 'fingerprint' => $fingerprint];
            }
        }
        $last = (int) $rows->last()->id;
        if ($documents === []) {
            DB::table('fms_search_syncs')->where('id', $state->id)->update(['after_id' => $last]);

            return ['state' => 'unchanged', 'checked' => count($rows)];
        }
        $task = $engine->publish($documents);
        $this->pending($state, $task, ['after' => $last, 'receipts' => $receipts]);

        return ['state' => 'submitted', 'checked' => count($rows)];
    }

    private function sweep(Meilisearch $engine, object $state, int $limit): array
    {
        $rows = DB::table('fms_search_receipts')->where('sync_id', $state->id)->where('id', '>', $state->after_id)->orderBy('id')->limit($limit)->get();
        if ($rows->isEmpty()) {
            DB::table('fms_search_syncs')->where('id', $state->id)->update(['phase' => 'scan', 'after_id' => 0]);

            return ['state' => 'complete', 'checked' => 0];
        }
        $existing = DB::table('fms_entries as e')->join('fms_workspaces as w', 'w.id', '=', 'e.workspace_id')->whereIn('e.id', $rows->pluck('entry_id'))->pluck('e.public_id', 'e.id');
        $missing = $rows->filter(fn ($row): bool => $existing->get($row->entry_id) !== $row->public_id);
        $last = (int) $rows->last()->id;
        if ($missing->isEmpty()) {
            DB::table('fms_search_syncs')->where('id', $state->id)->update(['after_id' => $last]);

            return ['state' => 'unchanged', 'checked' => count($rows)];
        }
        $task = $engine->taskId($engine->request('POST', '/indexes/'.$engine->index().'/documents/delete-batch', $missing->pluck('public_id')->values()->all()));
        $this->pending($state, $task, ['after' => $last, 'delete' => $missing->pluck('id')->values()->all()]);

        return ['state' => 'submitted', 'checked' => count($rows)];
    }

    private function pending(object $state, int $task, array $payload): void
    {
        DB::table('fms_search_syncs')->where('id', $state->id)->update(['task_id' => $task, 'pending' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    private function acknowledge(Meilisearch $engine, object $state): array
    {
        $task = $engine->request('GET', '/tasks/'.(int) $state->task_id);
        if (! in_array($task['status'] ?? null, ['succeeded', 'failed', 'canceled'], true)) {
            return ['state' => 'waiting', 'checked' => 0];
        }
        if (($task['status'] ?? null) !== 'succeeded') {
            DB::table('fms_search_syncs')->where('id', $state->id)->update(['task_id' => null, 'pending' => null]);

            return ['state' => 'failed', 'checked' => 0];
        }
        $payload = json_decode($state->pending, true, 8, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($state, $payload): void {
            if (! empty($payload['receipts'])) {
                DB::table('fms_search_receipts')->upsert($payload['receipts'], ['sync_id', 'entry_id'], ['public_id', 'fingerprint']);
            }
            if (! empty($payload['delete'])) {
                DB::table('fms_search_receipts')->where('sync_id', $state->id)->whereIn('id', $payload['delete'])->delete();
            }
            DB::table('fms_search_syncs')->where('id', $state->id)->update(['after_id' => $payload['after'], 'task_id' => null, 'pending' => null]);
        });

        return ['state' => 'acknowledged', 'checked' => 0];
    }
}
