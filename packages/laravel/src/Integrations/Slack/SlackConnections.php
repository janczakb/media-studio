<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Slack;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use UseFlex\MediaStudio\Identity\Principal;

final class SlackConnections
{
    // Private comments and mentions must never be broadcast to a shared channel.
    public const EVENTS = ['uploaded', 'guest_uploaded', 'processed', 'trashed', 'restored'];

    public function available(): bool
    {
        return config('media-studio.integrations.slack.enabled') === true
            && Schema::hasTable('fms_slack_connections') && Schema::hasTable('fms_slack_oauth_states');
    }

    public function payload(object $workspace, bool $canManage): array
    {
        $available = $this->available();
        $row = $available ? DB::table('fms_slack_connections')->where('workspace_id', $workspace->id)->first() : null;

        return ['available' => $available, 'canManage' => $canManage, 'events' => self::EVENTS,
            'oauthConfigured' => $available && SlackOAuth::configured(),
            'slack' => ['connected' => $row?->webhook !== null, 'enabled' => (bool) ($row?->enabled ?? false),
                'channel' => $row?->channel ?? '', 'teamName' => $row?->slack_team_name ?? null,
                'events' => $row ? json_decode($row->events, true, flags: JSON_THROW_ON_ERROR) : ['uploaded', 'guest_uploaded', 'processed'],
                'revision' => (int) ($row?->revision ?? 0), 'lastDeliveredAt' => $row?->last_delivered_at,
                'status' => $row?->last_error ?? ($row?->last_delivered_at ? 'delivered' : 'ready')]];
    }

    /** The caller holds the workspace row lock for either write operation. */
    public function save(object $workspace, Principal $principal, array $data): void
    {
        abort_unless($this->available(), 409, 'Slack is not enabled on this server.');
        $row = $this->current($workspace, (int) $data['revision']);
        abort_unless($row?->webhook, 409, 'Connect to Slack before saving settings.');
        $values = ['events' => json_encode($data['events'], JSON_THROW_ON_ERROR), 'enabled' => $data['enabled'],
            ...$this->ownership($principal, (int) $row->revision + 1)];
        if (! $row->enabled && $data['enabled']) {
            $values['activity_cursor'] = $this->cursor($workspace);
        }
        DB::table('fms_slack_connections')->where('workspace_id', $workspace->id)->update($values);
    }

    /** Only call with a validated installation obtained by the server-side OAuth exchange. */
    public function connect(object $workspace, Principal $principal, int $revision, array $installation): void
    {
        abort_unless($this->available(), 409);
        abort_unless(self::validWebhook($installation['webhook']), 422);
        $row = $this->current($workspace, $revision);
        $values = ['webhook' => Crypt::encryptString($installation['webhook']), 'channel' => $installation['channel'],
            'slack_team_id' => $installation['teamId'], 'slack_team_name' => $installation['teamName'], 'slack_channel_id' => $installation['channelId'],
            'events' => $row?->events ?? json_encode(['uploaded', 'guest_uploaded', 'processed'], JSON_THROW_ON_ERROR),
            'enabled' => true, 'activity_cursor' => $this->cursor($workspace), 'last_delivered_at' => null,
            ...$this->ownership($principal, $revision + 1)];
        if ($row) {
            DB::table('fms_slack_connections')->where('workspace_id', $workspace->id)->update($values);
        } else {
            DB::table('fms_slack_connections')->insert($values + ['workspace_id' => $workspace->id, 'created_at' => now()]);
        }
    }

    public function disconnect(object $workspace, int $revision): void
    {
        abort_unless($this->available(), 409);
        $updated = DB::table('fms_slack_connections')->where('workspace_id', $workspace->id)->where('revision', $revision)
            ->update(['webhook' => null, 'enabled' => false, 'revision' => $revision + 1, 'lease_token' => null, 'lease_until' => null,
                'last_error' => null, 'slack_team_id' => null, 'slack_team_name' => null, 'slack_channel_id' => null, 'updated_at' => now()]);
        abort_unless($updated === 1, 409, 'Connection changed. Refresh before disconnecting.');
    }

    private function current(object $workspace, int $revision): ?object
    {
        $row = DB::table('fms_slack_connections')->where('workspace_id', $workspace->id)->first();
        abort_unless((int) ($row?->revision ?? 0) === $revision, 409, 'Connection changed. Refresh before saving.');

        return $row;
    }

    private function cursor(object $workspace): int
    {
        return (int) (DB::table('fms_activity')->where('workspace_id', $workspace->id)->max('id') ?? 0);
    }

    private function ownership(Principal $principal, int $revision): array
    {
        return ['revision' => $revision, 'principal_type' => $principal->type, 'principal_id' => $principal->id,
            'available_at' => now(), 'lease_token' => null, 'lease_until' => null, 'attempts' => 0, 'last_error' => null, 'updated_at' => now()];
    }

    public static function validWebhook(string $url): bool
    {
        return (bool) preg_match('~\Ahttps://hooks\.slack(?:-gov)?\.com/services/[A-Za-z0-9_-]{5,64}/[A-Za-z0-9_-]{5,64}/[A-Za-z0-9_-]{10,128}\z~D', $url);
    }
}
