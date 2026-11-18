<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\BrowserPush;
use UseFlex\MediaStudio\Identity\Principal;

final class BrowserPushController
{
    public function __construct(private WorkspaceAccess $access) {}

    public function worker(): BinaryFileResponse
    {
        return response()->file(__DIR__.'/../../resources/js/push-worker.js', [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function show(Request $request, string $workspace): JsonResponse
    {
        [$principal, $resolved] = $this->context($request, $workspace);
        $device = $request->validate(['device' => ['nullable', 'regex:/\A[a-f0-9]{64}\z/']])['device'] ?? null;
        $available = BrowserPush::available();

        return response()->json([
            'available' => $available,
            'publicKey' => $available ? config('media-studio.notifications.push.public_key') : null,
            'subscribed' => BrowserPush::enabled() && $device !== null && DB::table('fms_push_devices')
                ->where('endpoint_hash', $device)->where('principal_key', $principal->key())->exists(),
            'events' => BrowserPush::enabled() ? BrowserPush::events((int) $resolved->id, $principal->key()) : [],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function preferences(Request $request, string $workspace): JsonResponse
    {
        [$principal, $resolved] = $this->context($request, $workspace);
        abort_unless(BrowserPush::available(), 503);
        $data = $request->validate([
            'events' => ['present', 'array', 'list', 'max:'.count(BrowserPush::EVENTS)],
            'events.*' => ['required', 'string', 'distinct', Rule::in(BrowserPush::EVENTS)],
        ]);
        DB::table('fms_push_preferences')->upsert([[
            'workspace_id' => $resolved->id, 'principal_key' => $principal->key(),
            'events' => json_encode($data['events'], JSON_THROW_ON_ERROR),
        ]], ['workspace_id', 'principal_key'], ['events']);

        return response()->json(['events' => $data['events']])->header('Cache-Control', 'private, no-store');
    }

    public function subscribe(Request $request, string $workspace): JsonResponse
    {
        [$principal] = $this->context($request, $workspace);
        abort_unless(BrowserPush::available(), 503);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'size:87'],
            'keys.auth' => ['required', 'string', 'size:22'],
            'locale' => ['required', 'string', 'max:64', 'regex:/\A[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*\z/'],
            'path' => ['required', 'string', 'max:1024', 'regex:#\A/(?!/)[A-Za-z0-9/_.~%\-]*\z#D'],
        ]);

        if (! BrowserPush::acceptsEndpoint($data['endpoint']) || ! BrowserPush::key($data['keys']['p256dh'], 65, "\x04")
            || ! BrowserPush::key($data['keys']['auth'], 16)) {
            throw ValidationException::withMessages(['endpoint' => __('media-studio::push.invalid_subscription')]);
        }

        $hash = hash('sha256', $data['endpoint']);
        DB::transaction(function () use ($principal, $data, $hash): void {
            $previous = DB::table('fms_push_devices')->where('endpoint_hash', $hash)->lockForUpdate()->first();
            $slots = DB::table('fms_push_devices')->where('principal_key', $principal->key())->pluck('slot')->all();
            $availableSlots = array_diff(range(0, BrowserPush::DEVICE_LIMIT - 1), $slots);
            $slot = $previous?->principal_key === $principal->key() ? $previous->slot
                : reset($availableSlots);
            if ($slot === false) {
                throw ValidationException::withMessages(['endpoint' => __('media-studio::push.device_limit')]);
            }
            if ($previous !== null && $previous->principal_key !== $principal->key()) {
                DB::table('fms_push_devices')->where('id', $previous->id)->delete();
            }

            $device = [
                'endpoint_hash' => $hash, 'principal_key' => $principal->key(), 'slot' => $slot,
                'return_path' => $data['path'], 'locale' => $data['locale'],
                'subscription' => Crypt::encryptString(json_encode(['endpoint' => $data['endpoint'], 'keys' => $data['keys']], JSON_THROW_ON_ERROR)),
            ];
            $created = DB::table('fms_push_devices')->insertOrIgnore($device);
            if ($created !== 1) {
                $owned = DB::table('fms_push_devices')->where('endpoint_hash', $hash)->where('principal_key', $principal->key());
                abort_unless($owned->exists(), 409);
                $owned->update($device);
            }
        }, 3);

        return response()->json(['subscribed' => true])->header('Cache-Control', 'private, no-store');
    }

    public function unsubscribe(Request $request, string $workspace, string $device): JsonResponse
    {
        [$principal] = $this->context($request, $workspace);
        DB::table('fms_push_devices')->where('endpoint_hash', $device)->where('principal_key', $principal->key())->delete();

        return response()->json(['subscribed' => false])->header('Cache-Control', 'private, no-store');
    }

    private function context(Request $request, string $workspace): array
    {
        $principal = Principal::fromUser($request->user());

        return [$principal, $this->access->resolve($principal, $workspace)];
    }
}
