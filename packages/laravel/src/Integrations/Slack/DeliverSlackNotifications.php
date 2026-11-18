<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Slack;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class DeliverSlackNotifications extends Command
{
    protected $signature = 'media-studio:integrations:slack {--limit=20 : Maximum connections to process}';

    protected $description = 'Deliver bounded batches of new Studio activity to connected Slack channels.';

    public function handle(SlackConnections $connections, WorkspaceAccess $access): int
    {
        if (! $connections->available()) {
            return self::SUCCESS;
        }
        SlackOAuth::prune();
        $ids = DB::table('fms_slack_connections')->where('enabled', true)->where('available_at', '<=', now())
            ->orderBy('available_at')->orderBy('id')->limit(max(1, min(100, (int) $this->option('limit'))))->pluck('id');
        $deadline = microtime(true) + 45;
        foreach ($ids as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $token = bin2hex(random_bytes(16));
            $claimed = DB::table('fms_slack_connections')->where('id', $id)->where('enabled', true)->where('available_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->update(['lease_token' => $token, 'lease_until' => now()->addMinutes(2), 'available_at' => now()->addMinutes(2)]);
            if (! $claimed) {
                continue;
            }
            $row = DB::table('fms_slack_connections')->where('id', $id)->where('lease_token', $token)->first();
            if ($row === null) {
                continue;
            }
            $owned = fn () => DB::table('fms_slack_connections')->where('id', $id)->where('revision', $row->revision)->where('lease_token', $token);
            try {
                $workspace = DB::table('fms_workspaces')->where('id', $row->workspace_id)->first();
                abort_if($workspace === null, 403);
                $workspace = $access->resolveForWorker(new Principal($row->principal_type, $row->principal_id), $workspace->public_id,
                    TenantIdentity::fromWorkspace($workspace), operation: 'integrations.manage');
                abort_unless(in_array($workspace->role, ['owner', 'admin'], true), 403);
            } catch (Throwable) {
                $owned()->update(['enabled' => false, 'last_error' => 'access_revoked', 'lease_token' => null, 'lease_until' => null]);

                continue;
            }
            // One indexed, bounded cursor scan per connection. No file contents, disk scans or per-file jobs.
            $activity = DB::table('fms_activity')->where('workspace_id', $row->workspace_id)->where('id', '>', $row->activity_cursor)
                ->orderBy('id')->limit(100)->get(['id', 'name', 'event', 'created_at']);
            $cursor = (int) ($activity->last()?->id ?? $row->activity_cursor);
            $events = array_intersect(json_decode($row->events, true, flags: JSON_THROW_ON_ERROR), SlackConnections::EVENTS);
            $eligible = $activity->filter(fn ($event) => in_array($event->event, $events, true) && $event->created_at >= now()->subDay()->toDateTimeString());
            try {
                if ($eligible->isNotEmpty()) {
                    $url = Crypt::decryptString($row->webhook);
                    if (! SlackConnections::validWebhook($url)) {
                        $owned()->update(['enabled' => false, 'last_error' => 'invalid_webhook', 'lease_token' => null, 'lease_until' => null]);

                        continue;
                    }
                    // Configuration revisions fence a disconnected or replaced connection.
                    if (! $owned()->where('enabled', true)->exists()) {
                        continue;
                    }
                    $clean = static fn (string $value, int $length): string => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '', 0, $length);
                    $labels = ['uploaded' => 'Uploaded', 'guest_uploaded' => 'Guest uploaded', 'processed' => 'Processing complete', 'trashed' => 'Moved to trash', 'restored' => 'Restored'];
                    $lines = [$clean($workspace->name, 100).' · FLEX Media Studio'];
                    foreach ($eligible->take(20) as $event) {
                        $lines[] = $labels[$event->event].': '.$clean($event->name, 140);
                    }
                    if ($eligible->count() > 20) {
                        $lines[] = '+'.($eligible->count() - 20).' more updates';
                    }
                    $response = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])->post($url,
                        ['text' => implode("\n", $lines), 'mrkdwn' => false, 'parse' => 'none', 'unfurl_links' => false, 'unfurl_media' => false]);
                    if ($response->status() !== 200 || trim($response->body()) !== 'ok') {
                        $status = $response->status();
                        $retry = $status === 429 || $status >= 500;
                        $attempt = (int) $row->attempts + 1;
                        $seconds = max(60, min(3600, (int) $response->header('Retry-After')));
                        $this->failure($owned, $attempt, $retry ? 'temporarily_unavailable' : 'webhook_rejected', $retry, $seconds);

                        continue;
                    }
                }
                $owned()->update(['activity_cursor' => $cursor, 'available_at' => now()->addMinute(), 'lease_token' => null,
                    'lease_until' => null, 'attempts' => 0, 'last_error' => null,
                    ...($eligible->isNotEmpty() ? ['last_delivered_at' => now()] : [])]);
            } catch (Throwable) {
                // Never log exceptions or response bodies: they can contain a webhook secret.
                $this->failure($owned, (int) $row->attempts + 1, 'connection_failed', true);
            }
        }

        return self::SUCCESS;
    }

    private function failure(callable $owned, int $attempt, string $code, bool $retry, int $minimumDelay = 60): void
    {
        $owned()->update(['attempts' => $attempt, 'last_error' => $code, 'enabled' => $retry && $attempt < 5,
            'available_at' => now()->addSeconds(max($minimumDelay, min(3600, 60 * 2 ** min(6, $attempt)))), 'lease_token' => null, 'lease_until' => null]);
    }
}
