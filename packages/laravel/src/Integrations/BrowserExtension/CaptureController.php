<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\BrowserExtension;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Library\EntryTags;
use UseFlex\MediaStudio\Library\FilterChoices;
use UseFlex\MediaStudio\Library\UploadAsset;

final class CaptureController
{
    public function connection(Request $request): JsonResponse
    {
        $token = $request->attributes->get('fms.extension-token');
        $w = app(ExtensionTokens::class)->workspace($token);

        return response()->json(['device' => $token->id, 'workspace' => ['id' => $w->public_id, 'name' => $w->name], 'expiresAt' => $token->expires_at, 'destination' => ['disk' => $w->disk], 'capabilities' => ['workspaces' => (bool) config('media-studio.workspaces.enabled', true), 'upload' => true, 'folders' => app(StudioPermissions::class)->allows($request->user(), 'folders.create', $w), 'notes' => app(StudioPermissions::class)->allows($request->user(), 'notes.create', $w)]]);
    }

    public function revoke(Request $request): JsonResponse
    {
        DB::table('fms_extension_tokens')->where('id', $request->attributes->get('fms.extension-token')->id)->update(['revoked_at' => now()]);

        return response()->json(['disconnected' => true]);
    }

    public function folders(Request $request, string $workspace): JsonResponse
    {
        $w = app(WorkspaceAccess::class)->resolve(Principal::fromUser($request->user()), $workspace);
        $d = $request->validate(['parent' => ['nullable', 'ulid'], 'after' => ['nullable', 'ulid', 'prohibits:before'], 'before' => ['nullable', 'ulid', 'prohibits:after'], 'search' => ['nullable', 'string', 'max:100']]);
        $parent = app(Entries::class)->parentId($w->id, $d['parent'] ?? null);
        $q = DB::table('fms_entries')->where('workspace_id', $w->id)->where('kind', 'folder')->whereNull('trashed_at')->where('parent_scope', $parent ?? 0)
            ->when(isset($d['after']), fn ($q) => $q->where('public_id', '>', $d['after']))
            ->when(isset($d['before']), fn ($q) => $q->where('public_id', '<', $d['before']))
            ->when(! empty($d['search']), fn ($q) => $q->whereRaw("normalized_name LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($d['search'])).'%']));
        $backward = isset($d['before']);
        $rows = $q->orderBy('public_id', $backward ? 'desc' : 'asc')->limit(61)->get(['public_id', 'name']);
        $page = $rows->take(60);
        if ($backward) {
            $page = $page->reverse();
        }
        $page = $page->values();

        return response()->json([
            'items' => $page->map(fn ($r) => ['id' => $r->public_id, 'name' => $r->name]),
            'nextCursor' => ($backward || $rows->count() > 60) ? $page->last()?->public_id : null,
            'previousCursor' => ((! $backward && isset($d['after'])) || ($backward && $rows->count() > 60)) ? $page->first()?->public_id : null,
        ]);
    }

    public function tags(Request $request, string $workspace, FilterChoices $choices): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $w = app(WorkspaceAccess::class)->resolve($principal, $workspace);
        $request->merge(['kind' => 'tags', 'limit' => 15]);

        return response()->json($choices->page($request, $w, $principal));
    }

    public function metadata(Request $request, string $workspace, string $entry): JsonResponse
    {
        $d = $request->validate(['key' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]{16,128}\z/'],
            'sourceUrl' => ['required', 'url:http,https', 'max:8192'], 'description' => ['nullable', 'string', 'max:4000'],
            'tags' => ['present', 'array', 'max:32'], 'tags.*' => ['string', 'max:120'],
            'tagRefs' => ['sometimes', 'array', 'max:32'], 'tagRefs.*' => ['array:id,name'],
            'tagRefs.*.id' => ['sometimes', 'required', 'ulid'], 'tagRefs.*.name' => ['required_without:tagRefs.*.id', 'string', 'max:120']]);
        $principal = Principal::fromUser($request->user());
        DB::transaction(function () use ($request, $workspace, $entry, $d, $principal): void {
            $w = app(WorkspaceAccess::class)->resolve($principal, $workspace, write: true, lock: true);
            $e = app(Entries::class)->resolve($w->id, $entry);
            abort_if($e->trashed_at !== null, 404);
            $token = $request->attributes->get('fms.extension-token');
            $ordinary = DB::table('fms_upload_receipts')->where('workspace_id', $w->id)->where('entry_id', $e->id)
                ->where('state', 'accepted')->where('key_hash', hash('sha256', 'extension:'.$token->id.':'.$d['key']))->exists();
            $chunk = DB::table('fms_upload_sessions')->where('workspace_id', $w->id)->where('entry_id', $e->id)->where('state', 'completed')
                ->where('principal_key', $principal->key())->where('client_scope', hash('sha256', 'extension:'.$token->id))->where('key_hash', hash('sha256', $d['key']))->exists();
            abort_unless($ordinary || $chunk, 403, 'This browser did not upload this file.');
            $metadata = json_decode($e->metadata ?? '{}', true, 32, JSON_THROW_ON_ERROR);
            $capture = ['sourceUrl' => $d['sourceUrl'], 'description' => $d['description'] ?? ''];
            if (isset($metadata['capture'])) {
                abort_unless($metadata['capture'] === $capture, 409);

                return;
            }
            if ($d['tags'] !== [] || ($d['tagRefs'] ?? []) !== []) {
                app(StudioPermissions::class)->authorize($request->user(), 'tags.manage', $w);
                app(EntryTags::class)->project($w, $e->id, array_slice(array_merge($d['tagRefs'] ?? [], array_map(fn ($name) => ['name' => $name], $d['tags'])), 0, 32));
            }
            $metadata['capture'] = $capture;
            DB::table('fms_entries')->where('id', $e->id)->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'revision' => $e->revision + 1]);
        }, 3);

        return response()->json(['saved' => true]);
    }

    public function upload(Request $request, string $workspace): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $w = app(WorkspaceAccess::class)->resolve($principal, $workspace, write: true);
        app(StudioPermissions::class)->authorize($request->user(), 'uploads.create', $w);
        $d = $request->validate(['parent' => ['nullable', 'ulid'], 'file' => ['required', 'file', 'max:'.config('media-studio.upload.max_kilobytes', 25600)], 'key' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]{16,128}\z/']]);
        $parent = app(Entries::class)->parentId($w->id, $d['parent'] ?? null, write: true);
        $tokenId = $request->attributes->get('fms.extension-token')->id;
        $guard = static function () use ($request, $workspace): void {
            $tokens = app(ExtensionTokens::class);
            $fresh = $tokens->authenticate($request->bearerToken());
            $w = $tokens->workspace($fresh);
            abort_unless($w->public_id === $workspace, 404);
            app(StudioPermissions::class)->authorize($request->user(), 'uploads.create', $w);
        };
        $entry = app(UploadAsset::class)->execute($w, $principal, $parent, $request->file('file'), 'extension:'.$tokenId.':'.$d['key'], beforePublish: $guard);

        return response()->json(app(Entries::class)->card($entry), 201);
    }
}
