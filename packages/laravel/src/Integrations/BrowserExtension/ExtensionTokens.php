<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\BrowserExtension;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class ExtensionTokens
{
    public static function prefix(): string
    {
        return '/'.trim((string) config('media-studio.integrations.browser_extension.path', 'media-studio/extension/v1'), '/');
    }

    public function authenticate(?string $bearer): object
    {
        abort_unless(config('media-studio.integrations.browser_extension.enabled') === true, 404);
        abort_unless(is_string($bearer) && preg_match('/\Afms_ext_([0-9A-HJKMNP-TV-Z]{26})\.([a-f0-9]{64})\z/', $bearer, $m), 401);
        $token = DB::table('fms_extension_tokens')->where('id', $m[1])->first();
        abort_unless($token !== null && $token->revoked_at === null && Carbon::parse($token->expires_at)->isFuture()
            && hash_equals($token->secret_hash, hash('sha256', $m[2])), 401);

        return $token;
    }

    public function workspace(object $token): object
    {
        $w = DB::table('fms_workspaces')->where('id', $token->workspace_id)->first();
        abort_if($w === null, 404);

        return app(WorkspaceAccess::class)->resolveForWorker(new Principal($token->principal_type, $token->principal_id), $w->public_id,
            TenantIdentity::fromWorkspace($w), write: true, operation: 'extension.use');
    }

    public function issue(Principal $principal, object $workspace, string $name): array
    {
        // Caller holds the workspace row lock, bounding concurrent device creation.
        $query = DB::table('fms_extension_tokens')->where('workspace_id', $workspace->id)->where('principal_key', $principal->key());
        abort_if((clone $query)->whereNull('revoked_at')->where('expires_at', '>', now())->count() >= 20, 409, 'Disconnect a browser before connecting another.');
        $secret = bin2hex(random_bytes(32));
        $id = (string) Str::ulid();
        $expires = now()->addDays(max(1, min(365, (int) config('media-studio.integrations.browser_extension.token_days', 30))));
        DB::table('fms_extension_tokens')->insert(['id' => $id, 'secret_hash' => hash('sha256', $secret), 'principal_type' => $principal->type,
            'principal_id' => $principal->id, 'principal_key' => $principal->key(), 'workspace_id' => $workspace->id, 'name' => $name,
            'expires_at' => $expires, 'revoked_at' => null, 'created_at' => now()]);

        return ['token' => 'fms_ext_'.$id.'.'.$secret, 'device' => $id, 'workspace' => ['id' => $workspace->public_id, 'name' => $workspace->name],
            'expiresAt' => $expires->toIso8601String(), 'apiBase' => url(self::prefix())];
    }
}
