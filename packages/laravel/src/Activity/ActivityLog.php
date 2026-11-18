<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Activity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UseFlex\MediaStudio\Identity\Principal;

/** Durable event and inbox writes participate in the caller's successful mutation. */
final class ActivityLog
{
    public function record(object $workspace, Principal $actor, object $entry, string $event, ?string $operationId = null, ?Principal $recipient = null): void
    {
        $this->recordMany($workspace, $actor, [$entry], $event, $operationId, $recipient);
    }

    /** @param iterable<object> $entries Fresh rows, with their committed revision. */
    public function recordMany(object $workspace, Principal $actor, iterable $entries, string $event, ?string $operationId = null, ?Principal $recipient = null): void
    {
        if (! config('media-studio.activity.enabled', true)) {
            return;
        }
        if (! in_array($event, BrowserPush::EVENTS, true)) {
            throw new InvalidArgumentException('Unsupported Studio activity event.');
        }
        $rows = [];
        $ids = [];
        foreach ($entries as $entry) {
            if (count($rows) >= 100 || (int) $entry->workspace_id !== (int) $workspace->id) {
                throw new InvalidArgumentException('Activity accepts up to 100 entries from one workspace.');
            }
            $key = hash('sha256', json_encode([$workspace->public_id, $entry->public_id, $event, $operationId ?? (string) $entry->revision], JSON_THROW_ON_ERROR));
            $ids[] = $entry->id;
            $rows[$key] = [
                'public_id' => (string) Str::ulid(), 'workspace_id' => $workspace->id,
                'entry_public_id' => $entry->public_id, 'event_key' => $key, 'event' => $event,
                'actor_type' => $actor->type, 'actor_id' => $actor->id, 'actor_key' => $actor->key(),
                'name' => $entry->name, 'kind' => $entry->kind, 'media_type' => $entry->media_type ?? null,
                'size_bytes' => $entry->size_bytes ?? null, 'revision' => $entry->revision, 'created_at' => now(),
            ];
        }
        if ($rows === []) {
            return;
        }
        DB::transaction(function () use ($workspace, $actor, $event, $ids, $rows, $recipient): void {
            DB::table('fms_activity')->insertOrIgnore(array_values($rows));
            if ($event === 'trashed') {
                DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereIn('id', $ids)->whereNotNull('trashed_at')
                    ->update(['trashed_by_key' => $actor->key(), 'trashed_by_type' => $actor->type, 'trashed_by_id' => $actor->id]);
            }
            if (! config('media-studio.notifications.enabled', true) || ($actor->type === 'guest' && $recipient === null)) {
                return;
            }
            $notifications = [];
            $notificationOwners = [];
            foreach (DB::table('fms_activity')->whereIn('event_key', array_keys($rows))->get(['id', 'actor_type', 'actor_id']) as $activity) {
                $notificationId = (string) Str::ulid();
                $owner = $recipient ?? new Principal($activity->actor_type, $activity->actor_id);
                $notificationOwners[$owner->key()]['owner'] = $owner;
                $notificationOwners[$owner->key()]['ids'][] = $notificationId;
                $notifications[] = ['public_id' => $notificationId, 'workspace_id' => $workspace->id,
                    'principal_key' => $owner->key(), 'activity_id' => $activity->id, 'read_at' => null, 'created_at' => now()];
            }
            $inserted = DB::table('fms_notifications')->insertOrIgnore($notifications);
            if ($inserted > 0) {
                foreach ($notificationOwners as $delivery) {
                    DB::afterCommit(fn () => app(InboxSummary::class)->forget((int) $workspace->id, $delivery['owner']));
                }
            }
            if ($inserted > 0 && BrowserPush::enabled()) {
                foreach ($notificationOwners as $delivery) {
                    app(BrowserPush::class)->enqueue($workspace, $delivery['owner'], $event, $delivery['ids']);
                }
            }
        });
    }
}
