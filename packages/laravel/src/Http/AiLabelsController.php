<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class AiLabelsController
{
    public function index(Request $request, string $workspace): JsonResponse
    {
        abort_unless(config('media-studio.intelligence.enabled') === true, 404);
        $actor = Principal::fromUser($request->user());
        $scope = app(WorkspaceAccess::class)->resolve($actor, $workspace);
        $input = $request->validate(['state' => ['nullable', 'in:pending,accepted,rejected,all'], 'cursor' => ['nullable', 'string', 'max:4096'], 'entry' => ['nullable', 'ulid']]);
        $state = $input['state'] ?? 'pending';
        $fingerprint = hash('sha256', json_encode([$actor->key(), $workspace, $state, $input['entry'] ?? null], JSON_THROW_ON_ERROR));
        $after = 0;
        if (isset($input['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($input['cursor']), true, 8, JSON_THROW_ON_ERROR);
                abort_unless(($cursor['scope'] ?? null) === $fingerprint && ($cursor['expires'] ?? 0) > time() && is_int($cursor['after'] ?? null) && $cursor['after'] > 0, 422);
                $after = $cursor['after'];
            } catch (DecryptException|\JsonException) {
                abort(422, 'Invalid label cursor.');
            }
        }
        $rows = $this->query($scope->id)->when(isset($input['entry']), fn ($query) => $query->where('e.public_id', $input['entry']))
            ->when($state !== 'all', fn ($query) => $query->where('l.review_state', $state))->where('l.id', '>', $after)->orderBy('l.id')->limit(61)
            ->get(['l.id', 'l.name', 'l.score', 'l.review_state', 'l.revision', 'a.provider', 'e.public_id', 'e.name as file_name']);
        $more = $rows->count() > 60;
        $rows = $rows->take(60);

        return response()->json(['canReview' => app(StudioPermissions::class)->allows($request->user(), 'ai.review', $scope), 'items' => $rows->map(static fn ($row): array => ['id' => (string) $row->id, 'name' => $row->name, 'score' => $row->score === null ? null : (float) $row->score, 'state' => $row->review_state, 'revision' => (int) $row->revision, 'provider' => $row->provider, 'entryId' => $row->public_id, 'fileName' => $row->file_name])->values(), 'hasMore' => $more, 'nextCursor' => $more ? Crypt::encryptString(json_encode(['scope' => $fingerprint, 'after' => (int) $rows->last()->id, 'expires' => time() + 900], JSON_THROW_ON_ERROR)) : null])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $workspace, string $label): JsonResponse
    {
        abort_unless(config('media-studio.intelligence.enabled') === true, 404);
        $input = $request->validate(['state' => ['required', 'in:pending,accepted,rejected'], 'revision' => ['required', 'integer', 'min:1']]);
        $actor = Principal::fromUser($request->user());
        $revision = DB::transaction(function () use ($actor, $workspace, $label, $input): int {
            $scope = app(WorkspaceAccess::class)->resolve($actor, $workspace, lock: true);
            $row = $this->query($scope->id)->where('l.id', $label)->lockForUpdate()->first(['l.id', 'l.revision']);
            abort_if($row === null, 404);
            abort_unless((int) $row->revision === (int) $input['revision'], 409, 'Label changed. Refresh before reviewing.');
            DB::table('fms_ai_labels')->where('id', $row->id)->update(['review_state' => $input['state'], 'revision' => $row->revision + 1, 'reviewed_by' => $actor->key(), 'reviewed_at' => now()]);

            return $row->revision + 1;
        });

        return response()->json(['id' => $label, 'state' => $input['state'], 'revision' => $revision])->header('Cache-Control', 'private, no-store');
    }

    private function query(int $workspace): Builder
    {
        return DB::table('fms_ai_labels as l')->join('fms_image_analyses as a', 'a.id', '=', 'l.analysis_id')->join('fms_entries as e', 'e.id', '=', 'a.entry_id')
            ->where('e.workspace_id', $workspace)->where('e.source_kind', 'managed')->whereNull('e.trashed_at')->whereColumn('a.version_id', 'e.current_version_id');
    }
}
