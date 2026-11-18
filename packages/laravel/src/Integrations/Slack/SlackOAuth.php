<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Slack;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class SlackOAuth
{
    public function __construct(private WorkspaceAccess $access, private SlackConnections $connections) {}

    public static function configured(): bool
    {
        return is_string(config('media-studio.integrations.slack.oauth.client_id'))
            && preg_match('/\A[0-9]+\.[0-9]+\z/', config('media-studio.integrations.slack.oauth.client_id'))
            && is_string(config('media-studio.integrations.slack.oauth.client_secret'))
            && config('media-studio.integrations.slack.oauth.client_secret') !== '';
    }

    public function start(Request $request, object $workspace, Principal $principal, int $revision, string $returnUrl): string
    {
        abort_unless($this->connections->available() && self::configured() && Schema::hasTable('fms_slack_oauth_states'), 409, 'Slack OAuth is not configured by the administrator.');
        abort_unless($request->hasSession(), 409, 'Slack authorization requires an authenticated browser session.');
        abort_unless($this->returnAllowed($returnUrl, $request), 422, 'Use a trusted Studio return address.');
        $redirectUri = config('media-studio.integrations.slack.oauth.redirect_uri') ?: route('media-studio.slack.callback');
        abort_unless(is_string($redirectUri) && filter_var($redirectUri, FILTER_VALIDATE_URL) && parse_url($redirectUri, PHP_URL_SCHEME) === 'https'
            && parse_url($redirectUri, PHP_URL_FRAGMENT) === null && parse_url($redirectUri, PHP_URL_USER) === null, 409, 'Configure an HTTPS Slack callback URL.');
        self::prune();
        $state = bin2hex(random_bytes(32));
        DB::transaction(function () use ($request, $workspace, $principal, $revision, $returnUrl, $redirectUri, $state): void {
            DB::table('fms_workspaces')->where('id', $workspace->id)->lockForUpdate()->first();
            $current = (int) (DB::table('fms_slack_connections')->where('workspace_id', $workspace->id)->value('revision') ?? 0);
            abort_unless($current === $revision, 409, 'Connection changed. Refresh before connecting.');
            $pending = DB::table('fms_slack_oauth_states')->where('principal_key', $principal->key())->where('expires_at', '>', now())->limit(5)->pluck('id');
            abort_if($pending->count() >= 5, 429, 'Finish the previous Slack authorization or wait ten minutes.');
            DB::table('fms_slack_oauth_states')->insert([
                'id' => hash('sha256', $state), 'workspace_id' => $workspace->id,
                'principal_key' => $principal->key(), 'session_key' => hash('sha256', $request->session()->getId()),
                'connection_revision' => $revision, 'return_url' => $returnUrl, 'redirect_uri' => $redirectUri,
                'expires_at' => now()->addMinutes(10),
            ]);
        });
        $domain = config('media-studio.integrations.slack.oauth.gov') === true ? 'slack-gov.com' : 'slack.com';

        return 'https://'.$domain.'/oauth/v2/authorize?'.http_build_query([
            'client_id' => config('media-studio.integrations.slack.oauth.client_id'), 'scope' => 'incoming-webhook',
            'state' => $state, 'redirect_uri' => $redirectUri,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function finish(Request $request): string
    {
        abort_unless($this->connections->available() && self::configured() && Schema::hasTable('fms_slack_oauth_states'), 404);
        abort_unless($request->hasSession(), 403);
        $principal = Principal::fromUser($request->user());
        $input = $request->validate(['state' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'code' => ['nullable', 'string', 'max:2048'], 'error' => ['nullable', 'string', 'max:80']]);
        // Consume once under a database lock; parallel callbacks cannot exchange a code twice.
        $state = DB::transaction(function () use ($input, $request, $principal): object {
            $query = DB::table('fms_slack_oauth_states')->where('id', hash('sha256', $input['state']))
                ->where('principal_key', $principal->key())->where('session_key', hash('sha256', $request->session()->getId()));
            $row = $query->lockForUpdate()->first();
            abort_if($row === null || $row->expires_at <= now()->toDateTimeString(), 403, 'Slack authorization expired or belongs to another session.');
            $query->delete();

            return $row;
        });
        $workspace = DB::table('fms_workspaces')->where('id', $state->workspace_id)->first();
        abort_if($workspace === null, 403);
        $tenant = TenantIdentity::fromWorkspace($workspace);
        $this->authorize($principal, $workspace->public_id, $tenant);
        if (($input['error'] ?? null) !== null || empty($input['code'])) {
            return $this->result($state->return_url, 'cancelled');
        }
        // Fail before the exchange if another tab changed/disconnected this workspace.
        $revision = (int) (DB::table('fms_slack_connections')->where('workspace_id', $workspace->id)->value('revision') ?? 0);
        if ($revision !== (int) $state->connection_revision) {
            return $this->result($state->return_url, 'conflict');
        }
        try {
            $domain = config('media-studio.integrations.slack.oauth.gov') === true ? 'slack-gov.com' : 'slack.com';
            $response = Http::asForm()->withBasicAuth(config('media-studio.integrations.slack.oauth.client_id'), config('media-studio.integrations.slack.oauth.client_secret'))
                ->connectTimeout(3)->timeout(10)->withOptions(['allow_redirects' => false])->post('https://'.$domain.'/api/oauth.v2.access',
                    ['code' => $input['code'], 'redirect_uri' => $state->redirect_uri]);
            $data = $response->json();
            if (! $response->successful() || ! is_array($data) || ($data['ok'] ?? false) !== true) {
                return $this->result($state->return_url, 'failed');
            }
            $installation = $this->installation($data);
            if ($installation === null) {
                return $this->result($state->return_url, 'failed');
            }
        } catch (Throwable) {
            // OAuth responses and exceptions can contain secrets. Never log them.
            return $this->result($state->return_url, 'failed');
        }
        try {
            DB::transaction(function () use ($principal, $workspace, $tenant, $state, $installation): void {
                $resolved = $this->authorize($principal, $workspace->public_id, $tenant, lock: true);
                $this->connections->connect($resolved, $principal, (int) $state->connection_revision, $installation);
            });
        } catch (Throwable) {
            return $this->result($state->return_url, 'conflict');
        }

        return $this->result($state->return_url, 'connected');
    }

    private function authorize(Principal $principal, string $workspace, ?TenantIdentity $tenant, bool $lock = false): object
    {
        $row = $this->access->resolveForWorker($principal, $workspace, $tenant, write: true, lock: $lock, operation: 'integrations.manage');
        abort_unless(in_array($row->role, ['owner', 'admin'], true), 403);

        return $row;
    }

    private function installation(array $data): ?array
    {
        $webhook = data_get($data, 'incoming_webhook.url');
        $channel = data_get($data, 'incoming_webhook.channel');
        $channelId = data_get($data, 'incoming_webhook.channel_id');
        $teamId = data_get($data, 'team.id');
        $teamName = data_get($data, 'team.name');
        if (! is_string($webhook) || ! SlackConnections::validWebhook($webhook)
            || ! is_string($channel) || $channel === '' || mb_strlen($channel) > 80
            || ! is_string($channelId) || ! preg_match('/\A[A-Z0-9]{5,32}\z/', $channelId)
            || ! is_string($teamId) || ! preg_match('/\AT[A-Z0-9]{4,31}\z/', $teamId)
            || ! in_array('incoming-webhook', explode(',', (string) ($data['scope'] ?? '')), true)) {
            return null;
        }

        return ['webhook' => $webhook, 'channel' => $channel, 'channelId' => $channelId,
            'teamId' => $teamId, 'teamName' => is_string($teamName) ? mb_substr($teamName, 0, 120) : $teamId];
    }

    private function returnAllowed(string $url, Request $request): bool
    {
        if (strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f]/', $url) || parse_url($url, PHP_URL_USER) !== null) {
            return false;
        }
        $parts = parse_url($url);
        if (! in_array($parts['scheme'] ?? null, ['https', 'http'], true)) {
            return false;
        }
        $origin = Str::lower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        $allowed = [Str::lower($request->getSchemeAndHttpHost()), ...(array) config('media-studio.integrations.slack.oauth.return_origins', [])];

        return in_array($origin, $allowed, true);
    }

    private function result(string $url, string $status): string
    {
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$path, $query] = array_pad(explode('?', $base, 2), 2, '');
        parse_str($query, $params);
        $params['fmsSlack'] = $status;

        return $path.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986).($fragment !== null ? '#'.$fragment : '');
    }

    public static function prune(): void
    {
        if (! Schema::hasTable('fms_slack_oauth_states')) {
            return;
        }
        $ids = DB::table('fms_slack_oauth_states')->where('expires_at', '<=', now())->orderBy('expires_at')->limit(100)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('fms_slack_oauth_states')->whereIn('id', $ids)->delete();
        }
    }
}
