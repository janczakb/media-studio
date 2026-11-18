<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Uploads;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Api\ApiTokens;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Integrations\BrowserExtension\ExtensionTokens;
use UseFlex\MediaStudio\Library\Entries;

final class UploadController
{
    public function __construct(private UploadSessions $uploads, private WorkspaceAccess $access, private Entries $entries) {}

    public function policy(Request $request, string $workspace): JsonResponse
    {
        $resolved = $this->access->resolve(Principal::fromUser($request->user()), $workspace, write: true);

        return response()->json($this->uploads->policy($resolved));
    }

    public function create(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'], 'size' => ['required', 'integer', 'min:1'],
            'parent' => ['nullable', 'ulid'], 'mime' => ['nullable', 'string', 'max:160'],
            'key' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]{16,128}\z/'],
            'direct' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->uploads->create(Principal::fromUser($request->user()), $workspace, $data, $this->clientScope($request)), 201);
    }

    public function status(Request $request, string $workspace, string $session): JsonResponse
    {
        return response()->json($this->uploads->card($this->uploads->resolve(Principal::fromUser($request->user()), $workspace, $session, clientScope: $this->clientScope($request))));
    }

    public function part(Request $request, string $workspace, string $session, string $part): JsonResponse
    {
        $hash = $request->header('X-Part-SHA256');
        abort_unless($hash === null || preg_match('/\A[a-f0-9]{64}\z/', $hash), 422, 'Invalid part checksum.');
        $stream = $request->getContent(true);
        try {
            $this->uploads->part(Principal::fromUser($request->user()), $workspace, $session, (int) $part, $stream, $hash, $this->clientScope($request));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return response()->json(['accepted' => true]);
    }

    public function signPart(Request $request, string $workspace, string $session, string $part): JsonResponse
    {
        $data = $request->validate(['sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);

        return response()->json($this->uploads->signPart(Principal::fromUser($request->user()), $workspace, $session, (int) $part, $data['sha256'], $this->clientScope($request)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function recordPart(Request $request, string $workspace, string $session, string $part): JsonResponse
    {
        $data = $request->validate(['etag' => ['required', 'string', 'regex:/\A"[\x21\x23-\x7e]{1,126}"\z/']]);
        $this->uploads->recordPart(Principal::fromUser($request->user()), $workspace, $session, (int) $part, $data['etag'], $this->clientScope($request));

        return response()->json(['accepted' => true]);
    }

    public function complete(Request $request, string $workspace, string $session): JsonResponse
    {
        return response()->json($this->entries->card($this->uploads->complete(Principal::fromUser($request->user()), $workspace, $session, $this->clientScope($request), $this->publicationGuard($request))));
    }

    private function clientScope(Request $request): ?string
    {
        $extension = $request->attributes->get('fms.extension-token');
        if ($extension !== null) {
            return hash('sha256', 'extension:'.$extension->id);
        }
        $token = $request->attributes->get('fms.api-token');

        return $token === null ? null : hash('sha256', 'rest:'.$token->public_id);
    }

    private function publicationGuard(Request $request): ?\Closure
    {
        if ($this->clientScope($request) === null) {
            return null;
        }

        return static function () use ($request): void {
            if ($request->attributes->has('fms.extension-token')) {
                $tokens = app(ExtensionTokens::class);
                $fresh = $tokens->authenticate($request->bearerToken());
                $resolved = $tokens->workspace($fresh);
                abort_unless($resolved->public_id === $request->route('workspace'), 404);
                app(StudioPermissions::class)->authorize($request->user(), 'uploads.create', $resolved);

                return;
            }
            app(ApiTokens::class)->authenticate($request->bearerToken());
            $w = app(WorkspaceAccess::class)->resolve(Principal::fromUser($request->user()), $request->route('workspace'), write: true);
            app(StudioPermissions::class)->authorize($request->user(), 'api.use', $w);
        };
    }

    public function cancel(Request $request, string $workspace, string $session): JsonResponse
    {
        $this->uploads->cancel(Principal::fromUser($request->user()), $workspace, $session, $this->clientScope($request));

        return response()->json(['cancelled' => true]);
    }
}
