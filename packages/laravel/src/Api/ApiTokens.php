<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Api;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class ApiTokens
{
    public static function prefix(): string
    {
        return '/'.trim((string) config('media-studio.api.path', 'media-studio/rest/v1'), '/');
    }

    public function issue(Authenticatable $actor, string $workspace, array $data): array
    {
        abort_unless(config('media-studio.api.enabled') === true, 503, 'API access is disabled.');
        $principal = Principal::fromUser($actor);
        $access = app(WorkspaceAccess::class);
        $tenant = $access->tenant($principal)?->key();

        return DB::transaction(function () use ($actor, $workspace, $data, $principal, $access, $tenant): array {
            // The workspace lock serializes the bounded per-owner token count.
            $w = $access->resolve($principal, $workspace, write: true, lock: true);
            app(StudioPermissions::class)->authorize($actor, 'api.use', $w);
            app(StudioPermissions::class)->authorize($actor, 'api.manage', $w);
            $query = DB::table('fms_api_tokens')->where('workspace_id', $w->id)->where('principal_key', $principal->key());
            abort_if((clone $query)->count() >= 100, 409, 'Token history is full. Contact your administrator.');
            abort_if((clone $query)->whereNull('revoked_at')->where('expires_at', '>', now())->count() >= 20, 409, 'Revoke an active token before creating another.');
            $secret = bin2hex(random_bytes(32));
            $id = (string) Str::ulid();
            $row = ['public_id' => $id, 'workspace_id' => $w->id, 'principal_key' => $principal->key(),
                'principal_type' => $principal->type, 'principal_id' => $principal->id, 'tenant_key' => $tenant,
                'name' => $data['name'], 'secret_hash' => hash('sha256', $secret), 'suffix' => substr($secret, -4),
                'can_write' => $data['scope'] === 'write', 'expires_at' => now()->addDays($data['days']),
                'created_at' => now(), 'revoked_at' => null, 'last_used_at' => null];
            DB::table('fms_api_tokens')->insert($row);

            return ['token' => 'fms_api_'.$id.'.'.$secret, 'item' => $this->card((object) $row)];
        });
    }

    public function authenticate(?string $bearer): object
    {
        abort_unless(is_string($bearer) && preg_match('/\Afms_api_([0-9A-HJKMNP-TV-Z]{26})\.([a-f0-9]{64})\z/', $bearer, $matches), 401, 'Invalid API token.', ['WWW-Authenticate' => 'Bearer']);
        $row = DB::table('fms_api_tokens as t')->join('fms_workspaces as w', 'w.id', '=', 't.workspace_id')->where('t.public_id', $matches[1])->select('t.*', 'w.public_id as workspace_public_id')->first();
        abort_unless($row !== null && hash_equals($row->secret_hash, hash('sha256', $matches[2])) && $row->revoked_at === null && Carbon::parse($row->expires_at)->isFuture(), 401, 'Invalid API token.', ['WWW-Authenticate' => 'Bearer']);

        return $row;
    }

    public function card(object $row): array
    {
        return ['id' => $row->public_id, 'name' => $row->name, 'suffix' => $row->suffix,
            'scope' => $row->can_write ? 'write' : 'read', 'status' => $row->revoked_at !== null ? 'revoked' : (Carbon::parse($row->expires_at)->isPast() ? 'expired' : 'active'),
            'expiresAt' => Carbon::parse($row->expires_at)->toIso8601String(), 'createdAt' => Carbon::parse($row->created_at)->toIso8601String(),
            'lastUsedAt' => $row->last_used_at === null ? null : Carbon::parse($row->last_used_at)->toIso8601String()];
    }
}
