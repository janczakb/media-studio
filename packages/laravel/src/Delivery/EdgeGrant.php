<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Delivery;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Renditions\Renditions;
use UseFlex\MediaStudio\Sharing\Shares;

/** Opaque, expiring authority. The edge must exchange it on EVERY request before its cache. */
final class EdgeGrant
{
    public function issue(DeliveryResource $resource, string $profile, int $expiresAt): ?string
    {
        $request = request();
        $entry = $request->route('entry');
        if (! is_string($entry)) {
            return null; // Prepared archives retain server authorization/fingerprint checks.
        }
        $share = null;
        $token = $request->route('token');
        if (is_string($token)) {
            [$record, , $principal] = app(Shares::class)->context($request, $token);
            $share = [$record->public_id, (int) $record->revision];
        } else {
            abort_unless($request->user() !== null, 401);
            $principal = Principal::fromUser($request->user());
        }

        return Crypt::encryptString(json_encode([
            'exp' => $expiresAt, 'profile' => $profile,
            'revision' => config('media-studio.delivery.profiles.'.$profile.'.revision', 1),
            'principal' => [$principal->type, $principal->id], 'share' => $share,
            'entry' => $entry, 'resource' => get_object_vars($resource),
        ], JSON_THROW_ON_ERROR));
    }

    public function authorize(string $grant): void
    {
        abort_unless(config('media-studio.delivery.enabled', false) && strlen($grant) <= 16384, 403);
        $data = json_decode(Crypt::decryptString($grant), true, 16, JSON_THROW_ON_ERROR);
        abort_unless(is_array($data) && is_int($data['exp'] ?? null) && $data['exp'] > now()->getTimestamp() && $data['exp'] <= now()->getTimestamp() + 3600, 403);
        $profile = config('media-studio.delivery.profiles.'.($data['profile'] ?? ''));
        abort_unless(is_array($profile) && ($profile['driver'] ?? '') === 'worker' && ($profile['revocation'] ?? 'per_request') === 'per_request'
            && ($profile['revision'] ?? 1) === ($data['revision'] ?? null), 403);
        $resource = new DeliveryResource(...$data['resource']);
        $principal = new Principal(...$data['principal']);
        $raw = DB::table('fms_workspaces')->where('public_id', $resource->workspace)->first();
        abort_if($raw === null, 403);
        $workspace = app(WorkspaceAccess::class)->resolveForWorker($principal, $raw->public_id, TenantIdentity::fromWorkspace($raw), write: $data['share'] !== null, operation: $data['share'] === null ? 'view' : 'shares.manage');
        if ($resource->download) {
            $permissions = app(StudioPermissions::class);
            $permissions->authorize($permissions->actor($principal), 'files.download', $workspace);
        }
        $shares = app(Shares::class);
        if ($data['share'] !== null) {
            $shares->enabled();
            $share = $shares->record($workspace, $data['share'][0]);
            abort_unless((int) $share->revision === $data['share'][1] && $share->revoked_at === null
                && ($share->expires_at === null || strtotime($share->expires_at) > now()->getTimestamp())
                && hash_equals($share->creator_key, $principal->key()) && (! $resource->download || $share->allow_download), 403);
            $entry = $shares->entry($share, $workspace, $principal, $data['entry']);
        } else {
            $entry = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('public_id', $data['entry'])->whereNull('trashed_at')->whereNull('purging_at')->first();
            abort_if($entry === null || $shares->accessible($workspace, $principal, [$entry]) === [], 403);
        }
        abort_unless($entry->kind === 'file', 403);
        $sources = app(SourcePolicy::class);
        $sources->readable($workspace, $principal, $entry);
        $version = DB::table('fms_asset_versions')->where('entry_id', $entry->id)->where('id', $resource->version)->first();
        abort_if($version === null, 403);
        if ($version->disk === $resource->disk && $version->object_key === $resource->key && $version->mime_type === $resource->mime) {
            $sources->original($workspace, $principal, (object) [...(array) $version, 'id' => $entry->id, 'source_kind' => $entry->source_kind, 'current_version_id' => $version->id]);

            return;
        }
        abort_unless($entry->current_version_id === $version->id, 403);
        $rendition = Renditions::published()->where('entry_id', $entry->id)->where('version_id', $version->id)
            ->where('disk', $resource->disk)->where('object_key', $resource->key)->where('mime_type', $resource->mime)
            ->when($resource->preset !== null, fn ($query) => $query->where('preset', $resource->preset))->first();
        if ($rendition !== null) {
            app(Renditions::class)->assertExternal($workspace, $entry, $rendition, $principal);

            return;
        }
        // Spatie may project its existing conversion directly, without a Studio rendition row.
        abort_unless($entry->source_kind === 'spatie', 403);
        foreach (['thumbnail', 'preview'] as $role) {
            if ($entry->{$role.'_state'} === 'ready' && $entry->{$role.'_version_id'} === $version->id
                && $entry->{$role.'_disk'} === $resource->disk && $entry->{$role.'_key'} === $resource->key) {
                $mime = $role === 'thumbnail' ? $sources->thumbnailMime($workspace, $principal, $entry) : $sources->previewMime($workspace, $principal, $entry);
                abort_unless($mime === $resource->mime, 403);

                return;
            }
        }
        abort(403);
    }

    public function check(Request $request): Response
    {
        try {
            $grant = $request->header('X-FMS-Grant');
            abort_unless(is_string($grant), 403);
            $this->authorize($grant);
            $status = 204;
        } catch (\Throwable) {
            $status = 403; // Network, database or policy failure must never release cached private bytes.
        }

        return response('', $status)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
