<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class ApiAccessController
{
    public function show(Request $request, string $workspace, ApiTokens $tokens): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $w = app(WorkspaceAccess::class)->resolve($principal, $workspace);
        $enabled = config('media-studio.api.enabled') === true;
        $canManage = app(StudioPermissions::class)->allows($request->user(), 'api.manage', $w) && in_array($w->role, ['owner', 'admin', 'editor'], true);
        $rows = DB::table('fms_api_tokens')->where('workspace_id', $w->id)->where('principal_key', $principal->key())->orderByDesc('id')->limit(100)->get();
        $days = now('UTC')->startOfDay()->subDays(29);
        $cacheSeconds = max(0, min(60, (int) config('media-studio.api.usage_cache_seconds', 10)));
        $loadUsage = static fn () => DB::table('fms_api_usage as u')->join('fms_api_tokens as t', 't.id', '=', 'u.token_id')
            ->where('t.workspace_id', $w->id)->where('t.principal_key', $principal->key())->where('u.day', '>=', $days->toDateString())
            ->selectRaw('u.day, SUM(u.requests) as requests, SUM(u.successes) as successes, SUM(u.client_errors) as client_errors, SUM(u.server_errors) as server_errors, SUM(u.duration_ms) as duration_ms')->groupBy('u.day')->get()->keyBy('day');
        $cacheKey = (string) config('media-studio.cache.prefix', 'fms').':api-usage:'.hash('sha256', json_encode([$w->id, $principal->key(), app(WorkspaceAccess::class)->tenant($principal)?->key(), $days->toDateString()], JSON_THROW_ON_ERROR));
        $usage = $cacheSeconds === 0 ? $loadUsage() : Cache::store(config('media-studio.cache.store'))->remember($cacheKey, $cacheSeconds, $loadUsage);
        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $date = $days->copy()->addDays($i)->toDateString();
            $row = $usage->get($date);
            $series[] = ['date' => $date, 'requests' => (int) ($row->requests ?? 0), 'successes' => (int) ($row->successes ?? 0),
                'errors' => (int) ($row->client_errors ?? 0) + (int) ($row->server_errors ?? 0), 'durationMs' => (int) ($row->duration_ms ?? 0)];
        }

        return response()->json(['enabled' => $enabled, 'canManage' => $canManage, 'endpoint' => url(ApiTokens::prefix()), 'workspace' => $workspace,
            'usageCacheSeconds' => $cacheSeconds, 'requestsPerMinute' => max(1, min(6000, (int) config('media-studio.api.requests_per_minute', 120))),
            'maxDays' => max(1, min(365, (int) config('media-studio.api.max_token_days', 90))),
            'items' => $rows->map($tokens->card(...))->values(), 'usage' => $series])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $workspace, ApiTokens $tokens): JsonResponse
    {
        $max = max(1, min(365, (int) config('media-studio.api.max_token_days', 90)));
        $data = $request->validate(['name' => ['required', 'string', 'max:80', 'not_regex:/[\x00-\x1F\x7F]/'],
            'scope' => ['required', 'in:read,write'], 'days' => ['required', 'integer', 'min:1', 'max:'.$max]]);

        return response()->json($tokens->issue($request->user(), $workspace, $data), 201)->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request, string $workspace, string $token): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $w = app(WorkspaceAccess::class)->resolve($principal, $workspace, write: true);
        $row = DB::table('fms_api_tokens')->where('workspace_id', $w->id)->where('principal_key', $principal->key())->where('public_id', $token)->first();
        abort_if($row === null, 404);
        DB::table('fms_api_tokens')->where('id', $row->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return response()->json(['revoked' => true])->header('Cache-Control', 'private, no-store');
    }
}
