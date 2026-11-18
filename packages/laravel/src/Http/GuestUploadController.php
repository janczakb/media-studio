<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Library\UploadAsset;
use UseFlex\MediaStudio\Sharing\Shares;

final class GuestUploadController
{
    public function store(Request $request, string $token, Shares $shares, UploadAsset $uploads, WorkspaceAccess $access)
    {
        abort_unless(config('media-studio.sharing.guest_upload_enabled', false), 404);
        [$share, $workspace, $principal] = $shares->context($request, $token);
        abort_unless($share->allow_upload && $share->kind === 'folder', 403);
        $limit = min((int) config('media-studio.upload.max_kilobytes', 25600), (int) config('media-studio.sharing.guest_upload_max_kilobytes', 25600));
        try {
            $data = $request->validate(['parent' => ['nullable', 'ulid'], 'file' => ['required', 'file', 'max:'.max(1, $limit)]]);
        } catch (ValidationException $error) {
            $error->redirectTo($shares->url($token));
            throw $error;
        }
        $key = 'fms-share-upload:'.$share->public_id.':'.hash('sha256', (string) $request->ip());
        abort_if(RateLimiter::increment($key, 60) > 20, 429);
        $root = DB::table('fms_share_entries as s')->join('fms_entries as e', 'e.id', '=', 's.entry_id')
            ->where('s.share_id', $share->id)->where('e.workspace_id', $workspace->id)->whereNull('e.trashed_at')->select('e.*')->first();
        abort_unless($root !== null && $root->kind === 'folder', 404);
        $folder = $shares->entry($share, $workspace, $principal, $data['parent'] ?? $root->public_id);
        abort_unless($folder->kind === 'folder', 404);
        app(SourcePolicy::class)->managed($folder);
        $recheck = function (bool $lock = false) use ($request, $token, $shares, $share, $folder, $access, $principal): object {
            [$current, $w] = $shares->context($request, $token);
            abort_unless(config('media-studio.sharing.guest_upload_enabled', false) && $current->allow_upload && (int) $current->revision === (int) $share->revision, 403);
            $destination = $shares->entry($current, $w, $principal, $folder->public_id);
            abort_unless($destination->kind === 'folder' && $destination->id === $folder->id, 404);
            app(SourcePolicy::class)->managed($destination);

            return $access->resolveForWorker($principal, $w->public_id, TenantIdentity::fromWorkspace($w), write: true, lock: $lock);
        };
        $clientKey = $request->header('Idempotency-Key') ?? $request->input('uploadKey') ?? (string) Str::ulid();
        abort_unless(is_string($clientKey) && preg_match('/\A[a-zA-Z0-9_-]{16,128}\z/', $clientKey), 422);
        $publish = function () use ($recheck, $share, $data): void {
            $recheck(true);
            $current = DB::table('fms_shares')->where('id', $share->id)->lockForUpdate()->first();
            abort_unless($current !== null && (int) $current->revision === (int) $share->revision && $current->allow_upload
                && $current->revoked_at === null && ($current->expires_at === null || strtotime($current->expires_at) > now()->getTimestamp()), 403);
            $maxFiles = max(1, (int) config('media-studio.sharing.guest_upload_max_files', 1000));
            $maxBytes = max(1, (int) config('media-studio.sharing.guest_upload_max_total_bytes', 1073741824));
            $bytes = (int) $data['file']->getSize();
            abort_if($current->guest_upload_count >= $maxFiles || $bytes > $maxBytes - $current->guest_upload_bytes, 422, 'This link has reached its upload limit.');
            DB::table('fms_shares')->where('id', $share->id)->update([
                'guest_upload_count' => $current->guest_upload_count + 1,
                'guest_upload_bytes' => $current->guest_upload_bytes + $bytes,
            ]);
        };
        try {
            $entry = $uploads->execute($workspace, $principal, $folder->id, $data['file'], hash('sha256', $share->public_id.':'.$clientKey),
                beforePublish: $publish, workspaceResolver: $recheck, activityActor: new Principal('guest', $share->public_id));
        } catch (HttpException $error) {
            if ($request->expectsJson() || ! in_array($error->getStatusCode(), [409, 422], true)) {
                throw $error;
            }

            return redirect($shares->url($token).'?parent='.rawurlencode($folder->public_id))
                ->withErrors(['file' => 'The file could not be uploaded. Check its type, size and the collection upload limits.']);
        }

        if (! $request->expectsJson()) {
            return redirect($shares->url($token).'?parent='.rawurlencode($folder->public_id))
                ->with('media-studio-share-success', 'Your file has been uploaded.');
        }

        return response()->json(['id' => $entry->public_id, 'name' => $entry->name], 201);
    }
}
