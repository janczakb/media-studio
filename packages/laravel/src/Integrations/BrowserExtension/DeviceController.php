<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\BrowserExtension;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class DeviceController
{
    public function discovery(): JsonResponse
    {
        $this->enabled();

        return response()->json(['protocol' => 1, 'apiBase' => url(ExtensionTokens::prefix()), 'studioUrl' => config('media-studio.integrations.browser_extension.studio_url')])->header('Cache-Control', 'no-store');
    }

    public function devices(Request $request, string $workspace): JsonResponse
    {
        $this->enabled();
        $principal = Principal::fromUser($request->user());
        $w = app(WorkspaceAccess::class)->resolve($principal, $workspace);
        app(StudioPermissions::class)->authorize($request->user(), 'extension.use', $w);
        $rows = DB::table('fms_extension_tokens')->where('workspace_id', $w->id)->where('principal_key', $principal->key())
            ->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('created_at')->limit(20)->get(['id', 'name', 'created_at', 'expires_at']);

        return response()->json(['items' => $rows])->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, string $workspace, string $device): JsonResponse
    {
        $this->enabled();
        $principal = Principal::fromUser($request->user());
        $w = app(WorkspaceAccess::class)->resolve($principal, $workspace);
        app(StudioPermissions::class)->authorize($request->user(), 'extension.use', $w);
        $count = DB::table('fms_extension_tokens')->where('id', $device)->where('workspace_id', $w->id)->where('principal_key', $principal->key())->update(['revoked_at' => now()]);
        abort_if($count === 0, 404);

        return response()->json(['revoked' => true]);
    }

    public function begin(Request $request): JsonResponse
    {
        $this->enabled();
        $d = $request->validate(['challenge' => ['required', 'regex:/\A[a-f0-9]{64}\z/'], 'name' => ['required', 'string', 'max:80']]);
        $this->limit('begin:'.hash('sha256', (string) $request->ip()), 10, 600);
        $id = (string) Str::ulid();
        $code = strtoupper(bin2hex(random_bytes(4)));
        DB::table('fms_extension_requests')->insert(['id' => $id, 'challenge' => $d['challenge'], 'code_hash' => hash('sha256', $code), 'name' => $d['name'], 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);
        $ui = (string) config('media-studio.integrations.browser_extension.studio_url', config('app.url'));
        abort_unless(filter_var($ui, FILTER_VALIDATE_URL) && in_array(parse_url($ui, PHP_URL_SCHEME), ['https', 'http'], true), 503, 'Configure the Studio URL.');
        $url = $ui.(str_contains($ui, '?') ? '&' : '?').http_build_query(['fmsView' => 'integrations', 'fmsExtensionRequest' => $id, 'fmsExtensionCode' => $code]);

        return response()->json(['id' => $id, 'code' => $code, 'verificationUrl' => $url, 'interval' => 5, 'expiresIn' => 600])->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request, string $workspace): JsonResponse
    {
        $this->enabled();
        $d = $request->validate(['id' => ['required', 'ulid'], 'code' => ['required', 'regex:/\A[A-F0-9]{8}\z/']]);
        DB::transaction(function () use ($request, $workspace, $d): void {
            $principal = Principal::fromUser($request->user());
            $w = app(WorkspaceAccess::class)->resolve($principal, $workspace, write: true, lock: true);
            app(StudioPermissions::class)->authorize($request->user(), 'extension.use', $w);
            app(StudioPermissions::class)->authorize($request->user(), 'uploads.create', $w);
            $row = DB::table('fms_extension_requests')->where('id', $d['id'])->lockForUpdate()->first();
            abort_unless($row !== null && $row->approved_at === null && Carbon::parse($row->expires_at)->isFuture() && hash_equals($row->code_hash, hash('sha256', $d['code'])), 409, 'This connection request expired or was already approved.');
            DB::table('fms_extension_requests')->where('id', $row->id)->update(['principal_type' => $principal->type, 'principal_id' => $principal->id, 'workspace_id' => $w->id, 'approved_at' => now()]);
        }, 3);

        return response()->json(['approved' => true])->header('Cache-Control', 'no-store');
    }

    public function claim(Request $request): JsonResponse
    {
        $this->enabled();
        $d = $request->validate(['id' => ['required', 'ulid'], 'verifier' => ['required', 'regex:/\A[a-f0-9]{64}\z/']]);
        $this->limit('claim:'.$d['id'], 2, 5);

        return DB::transaction(function () use ($d): JsonResponse {
            $row = DB::table('fms_extension_requests')->where('id', $d['id'])->lockForUpdate()->first();
            abort_unless($row !== null && Carbon::parse($row->expires_at)->isFuture() && hash_equals($row->challenge, hash('sha256', $d['verifier'])), 401);
            if ($row->approved_at === null) {
                return response()->json(['pending' => true], 202)->header('Cache-Control', 'no-store');
            }
            $principal = new Principal($row->principal_type, $row->principal_id);
            $stored = DB::table('fms_workspaces')->where('id', $row->workspace_id)->first();
            abort_if($stored === null, 404);
            $w = app(WorkspaceAccess::class)->resolveForWorker($principal, $stored->public_id, TenantIdentity::fromWorkspace($stored), write: true, lock: true, operation: 'extension.use');
            app(StudioPermissions::class)->authorize(app(StudioPermissions::class)->actor($principal), 'uploads.create', $w);
            $result = app(ExtensionTokens::class)->issue($principal, $w, $row->name);
            DB::table('fms_extension_requests')->where('id', $row->id)->delete();

            return response()->json($result)->header('Cache-Control', 'no-store');
        }, 3);
    }

    private function enabled(): void
    {
        abort_unless(config('media-studio.integrations.browser_extension.enabled') === true, 404);
    }

    private function limit(string $key, int $limit, int $seconds): void
    {
        $key = 'fms:extension:'.$key;
        abort_if(RateLimiter::tooManyAttempts($key, $limit), 429, 'Please wait before trying again.', ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        RateLimiter::hit($key, $seconds);
    }
}
