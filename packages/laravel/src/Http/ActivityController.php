<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\InboxSummary;
use UseFlex\MediaStudio\Activity\ScopedCursor;
use UseFlex\MediaStudio\Identity\AttributeMemberProfileResolver;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Trash\TrashDirectory;

final class ActivityController
{
    public function __construct(private WorkspaceAccess $access) {}

    public function trash(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(['all', 'files', 'folders', 'restorations'])],
            'actor' => ['nullable', Rule::in(['all', 'me'])], 'after' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:120'], 'cursor' => ['nullable', 'string', 'max:4096'],
        ]);

        return response()->json(app(TrashDirectory::class)->page(Principal::fromUser($request->user()), $workspace, $data));
    }

    public function notifications(Request $request, string $workspace): JsonResponse
    {
        abort_unless(config('media-studio.notifications.enabled', true), 404);
        $principal = Principal::fromUser($request->user());
        $resolved = $this->access->resolve($principal, $workspace);
        $data = $request->validate(['unread' => ['nullable', 'boolean'], 'limit' => ['nullable', 'integer', 'min:1', 'max:120'], 'cursor' => ['nullable', 'string', 'max:4096']]);
        $unread = (bool) ($data['unread'] ?? false);
        $codec = new ScopedCursor(hash('sha256', json_encode(['inbox', $workspace, $principal->key(), $unread], JSON_THROW_ON_ERROR)));
        $page = DB::table('fms_notifications as n')->join('fms_activity as a', 'a.id', '=', 'n.activity_id')
            ->where('n.workspace_id', $resolved->id)->where('n.principal_key', $principal->key())
            ->when($unread, fn ($q) => $q->whereNull('n.read_at'))->orderByDesc('n.id')
            ->cursorPaginate((int) ($data['limit'] ?? 60), ['n.id', 'n.public_id', 'n.read_at', 'n.created_at', 'a.event', 'a.name', 'a.entry_public_id', 'a.actor_type', 'a.actor_id', 'a.actor_key'], 'cursor', $codec->decode($data['cursor'] ?? null));

        $own = app(config('media-studio.member_profiles.resolver', AttributeMemberProfileResolver::class))->resolve($request->user());
        $profiles = [$principal->key() => ['name' => $own['name'], 'avatarUrl' => $own['avatarUrl']]];
        foreach (array_unique(array_column($page->items(), 'actor_type')) as $type) {
            $class = config('media-studio.principals.'.$type);
            if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            $ids = array_map(fn ($r) => $r->actor_id, array_filter($page->items(), fn ($r) => $r->actor_type === $type));
            $model = new $class;
            foreach ($model->newQuery()->whereIn($model->getAuthIdentifierName(), $ids)->get() as $actor) {
                $profile = app(config('media-studio.member_profiles.resolver', AttributeMemberProfileResolver::class))->resolve($actor);
                $profiles[Principal::fromUser($actor)->key()] = ['name' => $profile['name'], 'avatarUrl' => $profile['avatarUrl']];
            }
        }

        return response()->json(['items' => array_map(static fn ($row): array => [
            'id' => $row->public_id, 'event' => $row->event, 'name' => $row->name, 'entryId' => $row->entry_public_id,
            'actor' => $profiles[$row->actor_key] ?? null, 'createdAt' => Carbon::parse($row->created_at, 'UTC')->toISOString(), 'readAt' => $row->read_at === null ? null : Carbon::parse($row->read_at, 'UTC')->toISOString(),
        ], $page->items()), 'nextCursor' => $codec->encode($page->nextCursor()), 'previousCursor' => $codec->encode($page->previousCursor()), 'hasMore' => $page->hasMorePages(), 'unreadCount' => app(InboxSummary::class)->count((int) $resolved->id, $principal)])->header('Cache-Control', 'private, no-store');
    }

    public function readVisible(Request $request, string $workspace): JsonResponse
    {
        abort_unless(config('media-studio.notifications.enabled', true), 404);
        $principal = Principal::fromUser($request->user());
        $resolved = $this->access->resolve($principal, $workspace);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:120'], 'ids.*' => ['required', 'ulid', 'distinct']]);
        $changed = DB::table('fms_notifications')->where('workspace_id', $resolved->id)->where('principal_key', $principal->key())->whereIn('public_id', $data['ids'])->whereNull('read_at')->update(['read_at' => now()]);
        app(InboxSummary::class)->forget((int) $resolved->id, $principal);

        return response()->json(['updated' => $changed, 'unreadCount' => app(InboxSummary::class)->count((int) $resolved->id, $principal)])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, string $workspace, string $notification): JsonResponse
    {
        abort_unless(config('media-studio.notifications.enabled', true), 404);
        $principal = Principal::fromUser($request->user());
        $resolved = $this->access->resolve($principal, $workspace);
        $data = $request->validate(['read' => ['required', 'boolean']]);
        $query = DB::table('fms_notifications')->where('workspace_id', $resolved->id)->where('principal_key', $principal->key())->where('public_id', $notification);
        abort_unless($query->exists(), 404);
        $readAt = $data['read'] ? now() : null;
        $query->update(['read_at' => $readAt]);
        app(InboxSummary::class)->forget((int) $resolved->id, $principal);

        return response()->json(['id' => $notification, 'readAt' => $readAt?->toIso8601String()]);
    }

    public function readAll(Request $request, string $workspace): JsonResponse
    {
        abort_unless(config('media-studio.notifications.enabled', true), 404);
        $principal = Principal::fromUser($request->user());
        $resolved = $this->access->resolve($principal, $workspace);
        $data = $request->validate(['beforeId' => ['nullable', 'ulid']]);
        $query = DB::table('fms_notifications')->where('workspace_id', $resolved->id)->where('principal_key', $principal->key());
        $cutoff = isset($data['beforeId']) ? (clone $query)->where('public_id', $data['beforeId'])->value('id') : $query->max('id');
        abort_if(isset($data['beforeId']) && $cutoff === null, 404);
        $changed = $cutoff === null ? 0 : $query->where('id', '<=', $cutoff)->whereNull('read_at')->update(['read_at' => now()]);

        app(InboxSummary::class)->forget((int) $resolved->id, $principal);

        return response()->json(['updated' => $changed, 'unreadCount' => app(InboxSummary::class)->count((int) $resolved->id, $principal)]);
    }
}
