<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\ScopedCursor;
use UseFlex\MediaStudio\Delivery\Delivery;
use UseFlex\MediaStudio\Delivery\DeliveryResource;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\EntryOperations;
use UseFlex\MediaStudio\Mail\StudioMail;
use UseFlex\MediaStudio\Renditions\Renditions;
use UseFlex\MediaStudio\Sharing\ArchiveDownload;
use UseFlex\MediaStudio\Sharing\ShareEngagement;
use UseFlex\MediaStudio\Sharing\Shares;

final class SharingController
{
    public function __construct(private Shares $shares, private SourcePolicy $sources) {}

    public function index(Request $request, string $workspace): JsonResponse
    {
        [$w, $principal] = $this->shares->workspace($request, $workspace);
        $data = $request->validate([
            'status' => ['sometimes', 'in:active,expired,revoked,all'], 'kind' => ['sometimes', 'in:all,file,folder,selection'],
            'summary' => ['sometimes', 'boolean'], 'audience' => ['sometimes', 'in:link,invite'], 'entry' => ['sometimes', 'ulid'], 'visibility' => ['sometimes', 'in:share,publish'], 'mine' => ['sometimes', 'boolean'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        $query = DB::table('fms_shares')->where('workspace_id', $w->id);
        $status = $data['status'] ?? 'active';
        if ($status === 'active') {
            $query->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->utc()));
        } elseif ($status === 'expired') {
            $query->whereNull('revoked_at')->where('expires_at', '<=', now()->utc());
        } elseif ($status === 'revoked') {
            $query->whereNotNull('revoked_at');
        }
        if (($data['kind'] ?? 'all') !== 'all') {
            $query->where('kind', $data['kind']);
        }
        if (($data['mine'] ?? false) || $w->role === 'viewer') {
            $query->where('creator_key', $principal->key());
        }
        if (isset($data['audience'])) {
            $data['audience'] === 'invite' ? $query->whereNotNull('recipient_email') : $query->whereNull('recipient_email');
        }
        if (isset($data['visibility'])) {
            $query->where('visibility', $data['visibility']);
        }
        if (isset($data['entry'])) {
            $entry = DB::table('fms_entries')->where('workspace_id', $w->id)->where('public_id', $data['entry'])->value('id');
            $query->whereIn('id', DB::table('fms_share_entries')->select('share_id')->where('entry_id', $entry));
        }
        $scope = new ScopedCursor(implode(':', ['share-list', $w->public_id, $principal->key(), $w->role, $status, $data['kind'] ?? 'all', (int) ($data['mine'] ?? false), $data['entry'] ?? '', $data['visibility'] ?? '', $data['audience'] ?? '']));
        $page = $query->orderByDesc('id')->cursorPaginate($data['limit'] ?? 40, ['*'], 'cursor', $scope->decode($data['cursor'] ?? null));

        $summaries = collect();
        if ($data['summary'] ?? false) {
            $ids = array_map(fn (object $row): int => (int) $row->id, array_filter($page->items(), fn (object $row): bool => $row->collect_analytics && $this->shares->canEdit($w, $principal, $row)));
            if ($ids !== []) {
                $summaries = DB::table('fms_share_daily')->whereIn('share_id', $ids)->whereBetween('day', [now()->utc()->subDays(29)->toDateString(), now()->utc()->toDateString()])
                    ->selectRaw('share_id, SUM(visits) AS visits, MAX(last_visited_at) AS last_visited_at')
                    ->groupBy('share_id')->get()->keyBy('share_id');
            }
        }

        return $this->json([
            'items' => array_map(function (object $share) use ($w, $principal, $summaries, $data): array {
                $summary = $summaries->get($share->id);

                return [...$this->shares->card($w, $principal, $share), ...(($data['summary'] ?? false) && $share->collect_analytics && $this->shares->canEdit($w, $principal, $share)
                    ? ['visits30d' => (int) ($summary->visits ?? 0), 'lastVisitedAt' => empty($summary->last_visited_at) ? null : Carbon::parse($summary->last_visited_at, 'UTC')->toIso8601String()] : [])];
            }, $page->items()),
            'nextCursor' => $scope->encode($page->nextCursor()), 'previousCursor' => $scope->encode($page->previousCursor()),
            'hasMore' => $page->hasMorePages(), 'canManage' => in_array($w->role, ['owner', 'admin', 'editor'], true),
        ]);
    }

    public function store(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            ...$this->fields(), 'title' => ['required', 'string', 'max:160'],
            'entryIds' => ['required', 'array', 'list', 'min:1', 'max:'.max(1, min(120, (int) config('media-studio.sharing.max_items', 120)))],
            'entryIds.*' => ['required', 'ulid', 'distinct'],
            'recipientPermission' => ['sometimes', 'in:view,comment,edit'],
        ]);
        $result = DB::transaction(function () use ($request, $workspace, $data): array {
            [$w, $principal] = $this->shares->workspace($request, $workspace, write: true, lock: true);

            return $this->shares->create($w, $principal, $data);
        });

        return $this->json($result, 201);
    }

    public function update(Request $request, string $workspace, string $share): JsonResponse
    {
        $data = $request->validate([...$this->fields(), 'revision' => ['required', 'integer', 'min:1'], 'unpublish' => ['sometimes', 'boolean'], 'revoked' => ['sometimes', 'boolean'], 'recipientPermission' => ['sometimes', 'in:view,comment,edit']]);
        $result = DB::transaction(function () use ($request, $workspace, $share, $data): array {
            [$w, $principal] = $this->shares->workspace($request, $workspace, write: true, lock: true);
            $row = $this->shares->record($w, $share);
            $this->shares->editable($w, $principal, $row, (int) $data['revision']);
            $values = ['revision' => $row->revision + 1, 'updated_at' => now()];
            foreach (['title' => 'title', 'allowDownload' => 'allow_download', 'allowArchive' => 'allow_archive', 'allowUpload' => 'allow_upload', 'collectAnalytics' => 'collect_analytics', 'allowIndexing' => 'allow_indexing', 'allowCopy' => 'allow_copy'] as $key => $column) {
                if (array_key_exists($key, $data)) {
                    $values[$column] = $data[$key];
                }
            }
            if (isset($data['recipientPermission'])) {
                abort_if($row->visibility !== 'share', 422);
                $values['recipient_permission'] = $data['recipientPermission'];
            }
            if ($data['allowUpload'] ?? false) {
                $roots = DB::table('fms_share_entries as s')->join('fms_entries as e', 'e.id', '=', 's.entry_id')->where('s.share_id', $row->id)->whereNull('e.trashed_at')->select('e.*')->get();
                abort_unless(config('media-studio.sharing.guest_upload_enabled', false) && $roots->count() === 1 && $roots->first()->kind === 'folder' && $roots->first()->source_kind === 'managed' && count($this->shares->accessible($w, $principal, $roots->all())) === 1, 422, 'Guest uploads require one managed folder.');
            }
            if (! ($data['allowDownload'] ?? $row->allow_download)) {
                $values['allow_archive'] = false;
                $values['allow_copy'] = false;
            }
            if (array_key_exists('password', $data)) {
                $values['password_hash'] = $data['password'] === null ? null : Shares::hashPassword($data['password']);
            }
            if ($row->visibility !== 'publish') {
                $values['allow_copy'] = false;
            }
            if ($row->recipient_email !== null || $row->visibility !== 'publish' || ($values['password_hash'] ?? (array_key_exists('password', $data) ? null : $row->password_hash)) !== null) {
                $values['allow_indexing'] = false;
            }
            if (array_key_exists('expiresAt', $data)) {
                $values['expires_at'] = $data['expiresAt'] === null ? null : Carbon::parse($data['expiresAt'])->utc();
            }
            if (array_key_exists('revoked', $data)) {
                $values['revoked_at'] = $data['revoked'] ? now() : null;
            }
            if ($data['unpublish'] ?? false) {
                abort_unless($row->visibility === 'publish', 422, 'Only published links can be unpublished.');
                // Removing publication also revokes its public URL. It must not
                // silently become a still-accessible ordinary share link.
                $values['visibility'] = 'share';
                $values['revoked_at'] = now();
                $values['allow_indexing'] = false;
                $values['allow_copy'] = false;
            }
            DB::table('fms_shares')->where('id', $row->id)->update($values);

            return $this->shares->card($w, $principal, $this->shares->record($w, $share));
        });

        return $this->json($result);
    }

    public function rotate(Request $request, string $workspace, string $share): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $result = DB::transaction(function () use ($request, $workspace, $share, $data): array {
            [$w, $principal] = $this->shares->workspace($request, $workspace, write: true, lock: true);
            $row = $this->shares->record($w, $share);
            $this->shares->editable($w, $principal, $row, (int) $data['revision']);
            $token = bin2hex(random_bytes(32));
            DB::table('fms_shares')->where('id', $row->id)->update(['token_hash' => hash('sha256', $token), 'encrypted_token' => Crypt::encryptString($token), 'revision' => $row->revision + 1, 'updated_at' => now()]);

            return [...$this->shares->card($w, $principal, $this->shares->record($w, $share)), 'url' => $this->shares->url($token)];
        });

        return $this->json($result);
    }

    public function invite(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'emails' => ['required', 'array', 'list', 'min:1', 'max:10'],
            'emails.*' => ['required', 'string', 'email:rfc', 'max:254'],
            'permission' => ['required', 'in:view,comment,edit'],
            'title' => ['required', 'string', 'max:160'],
            'entryIds' => ['required', 'array', 'list', 'min:1', 'max:'.max(1, min(120, (int) config('media-studio.sharing.max_items', 120)))],
            'entryIds.*' => ['required', 'ulid', 'distinct'],
        ]);
        [$w, $principal] = $this->shares->workspace($request, $workspace, write: true);
        abort_if(RateLimiter::increment('fms-share-invite:'.$w->id.':'.$principal->key(), 60) > 5, 429);
        $emails = array_values(array_unique(array_map(fn (string $email): string => mb_strtolower(trim($email)), $data['emails'])));
        $links = DB::transaction(function () use ($request, $workspace, $data, $emails): array {
            [$w, $principal] = $this->shares->workspace($request, $workspace, write: true, lock: true);

            return array_map(fn (string $email): array => $this->shares->create($w, $principal, [
                ...$data, 'recipientEmail' => $email, 'recipientPermission' => $data['permission'],
            ]), $emails);
        });
        $failed = [];
        foreach ($links as $link) {
            try {
                StudioMail::raw('You have been invited to view a shared collection in Media Studio. Sign in with the verified email address receiving this message to open it.

'.$link['url'],
                    fn ($message) => $message->to($link['recipientEmail'])->subject('Media Studio invitation'));
            } catch (TransportExceptionInterface $error) {
                report($error);
                $failed[] = $link['recipientEmail'];
            }
        }

        return $this->json(['items' => array_map(fn (array $link) => Arr::except($link, 'url'), $links), 'failed' => $failed], 201);
    }

    public function comment(Request $request, string $token): RedirectResponse
    {
        [$share] = $this->shares->context($request, $token);
        abort_unless($request->user() && in_array($share->recipient_permission, ['comment', 'edit'], true), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($share, $data): void {
            $current = DB::table('fms_shares')->where('id', $share->id)->lockForUpdate()->first();
            abort_unless($current && (int) $current->revision === (int) $share->revision, 409);
            abort_if(DB::table('fms_share_guest_comments')->where('share_id', $share->id)->count() >= 1000, 422);
            DB::table('fms_share_guest_comments')->insert(['share_id' => $share->id, 'body' => $data['body'], 'created_at' => now()->utc()]);
        });

        return redirect($this->shares->url($token));
    }

    public function rename(Request $request, string $token, string $entry, EntryOperations $entries): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191'], 'revision' => ['required', 'integer', 'min:1']]);
        [$share, $workspace, $principal] = $this->shares->context($request, $token);
        abort_unless($request->user() && $share->recipient_permission === 'edit', 403);
        $entries->execute($principal, $workspace->public_id, $entry, 'update', $data, function (object $locked) use ($request, $token, $share, $entry): void {
            [$current, $w, $actor] = $this->shares->context($request, $token);
            abort_unless((int) $current->revision === (int) $share->revision && $w->id === $locked->id, 403);
            $this->shares->entry($current, $w, $actor, $entry);
            $permissions = app(StudioPermissions::class);
            $permissions->authorize($permissions->actor($actor), 'files.update', $w);
        });

        return redirect($this->shares->url($token));
    }

    public function link(Request $request, string $workspace, string $share): JsonResponse
    {
        [$w, $principal] = $this->shares->workspace($request, $workspace, write: true);
        $row = $this->shares->record($w, $share);
        abort_unless($this->shares->canEdit($w, $principal, $row), 403);
        abort_if($row->encrypted_token === null || $row->revoked_at !== null, 404);
        $token = Crypt::decryptString($row->encrypted_token);
        abort_unless(hash_equals($row->token_hash, hash('sha256', $token)), 404);

        return $this->json(['url' => $this->shares->url($token)]);
    }

    public function analytics(Request $request, string $workspace, string $share, ShareEngagement $engagement): JsonResponse
    {
        $input = $request->validate(['days' => ['sometimes', 'integer', 'in:7,30,90']]);
        [$w, $principal] = $this->shares->workspace($request, $workspace);
        $row = $this->shares->record($w, $share);
        abort_unless($this->shares->canEdit($w, $principal, $row), 403);

        return $this->json($engagement->report($row, (int) ($input['days'] ?? 30)));
    }

    public function destroy(Request $request, string $workspace, string $share): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $workspace, $share, $data): void {
            [$w, $principal] = $this->shares->workspace($request, $workspace, write: true, lock: true);
            $row = $this->shares->record($w, $share);
            $this->shares->editable($w, $principal, $row, (int) $data['revision']);
            DB::table('fms_shares')->where('id', $row->id)->delete();
        });

        return $this->json(['deleted' => true]);
    }

    public function engagement(Request $request, string $token, ShareEngagement $engagement): Response
    {
        [$share] = $this->shares->context($request, $token);
        $data = $request->validate(['ticket' => ['required', 'string', 'max:1024'], 'seconds' => ['required', 'integer', 'min:1', 'max:1800']]);
        $engagement->duration($share, $data['ticket'], (int) $data['seconds']);

        return response()->noContent();
    }

    public function roots(Request $request, string $workspace, string $share): JsonResponse
    {
        [$w, $principal] = $this->shares->workspace($request, $workspace);
        $row = $this->shares->record($w, $share);
        abort_unless($this->shares->canEdit($w, $principal, $row), 403);
        $roots = DB::table('fms_share_entries as s')->join('fms_entries as e', 'e.id', '=', 's.entry_id')
            ->where('s.share_id', $row->id)->where('e.workspace_id', $w->id)->whereNull('e.trashed_at')->orderBy('s.position')->limit(120)->select('e.*')->get();

        return $this->json(['items' => array_map(fn (object $entry): array => ['id' => $entry->public_id, 'name' => $entry->name, 'kind' => $entry->kind], $this->shares->accessible($w, $principal, $roots->all()))]);
    }

    public function unlock(Request $request, string $token): JsonResponse|RedirectResponse
    {
        [$share] = $this->shares->context($request, $token, password: false);
        try {
            $data = $request->validate(['password' => ['required', 'string', 'max:128']]);
        } catch (ValidationException $error) {
            $error->redirectTo($this->shares->url($token));
            throw $error;
        }
        $key = 'fms-share-password:'.$share->public_id.':'.hash('sha256', (string) $request->ip());
        $maximum = max(1, min(20, (int) config('media-studio.sharing.password_attempts', 5)));
        abort_if(RateLimiter::increment($key, 60) > $maximum, 429, 'Too many attempts. Try again shortly.');
        if ($share->password_hash !== null && ! Shares::checkPassword($data['password'], $share->password_hash)) {
            if ($request->expectsJson()) {
                return $this->json(['message' => 'The password is incorrect.'], 422);
            }

            return redirect($this->shares->url($token))->with('media-studio-share-error', 'The password is incorrect.');
        }
        RateLimiter::clear($key);
        $request->session()->put($this->shares->grantKey($share), (int) $share->revision);

        return $request->expectsJson() ? $this->json(['unlocked' => true]) : redirect($this->shares->url($token));
    }

    public function publicEntries(Request $request, string $token): JsonResponse
    {
        [$share, $workspace, $principal] = $this->shares->context($request, $token);

        return $this->json($this->publicPage($request, $token, $share, $workspace, $principal));
    }

    public function archive(Request $request, string $token, ArchiveDownload $archives): StreamedResponse
    {
        [$share, $workspace, $principal] = $this->shares->context($request, $token);
        abort_unless($share->allow_download && $share->allow_archive, 403);
        $data = $request->validate(['parent' => ['nullable', 'ulid']]);
        if (isset($data['parent'])) {
            $folder = $this->shares->entry($share, $workspace, $principal, $data['parent']);
            abort_unless($folder->kind === 'folder', 404);
            $roots = [$folder];
        } else {
            $roots = DB::table('fms_share_entries as s')->join('fms_entries as e', 'e.id', '=', 's.entry_id')
                ->where('s.share_id', $share->id)->where('e.workspace_id', $workspace->id)->whereNull('e.trashed_at')
                ->orderBy('s.position')->limit(120)->select('e.*')->get()->all();
            $roots = $this->shares->accessible($workspace, $principal, $roots);
        }
        abort_if($roots === [], 404);

        return $archives->response($workspace, $principal, $roots, function () use ($request, $token, $share, $data, $workspace, $principal): void {
            [$current] = $this->shares->context($request, $token);
            abort_unless($current->allow_download && $current->allow_archive && (int) $current->revision === (int) $share->revision, 403);
            if (isset($data['parent'])) {
                $this->shares->entry($current, $workspace, $principal, $data['parent']);
            }
        }, 'shared-collection');
    }

    private function publicPage(Request $request, string $token, object $share, object $workspace, Principal $principal): array
    {
        $data = $request->validate(['parent' => ['nullable', 'ulid'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:60'], 'cursor' => ['nullable', 'string', 'max:2048']]);
        $page = $this->shares->page($share, $workspace, $principal, $data['parent'] ?? null, (int) ($data['limit'] ?? 40), $data['cursor'] ?? null);
        $page['items'] = array_map(fn (object $row): array => $this->publicCard($token, $share, $row), $page['items']);

        return ['title' => $share->title, 'allowDownload' => (bool) $share->allow_download, 'allowArchive' => (bool) $share->allow_archive, ...$page];
    }

    public function content(Request $request, string $token, string $entry, string $operation): \Symfony\Component\HttpFoundation\Response
    {
        [$share, $workspace, $principal] = $this->shares->context($request, $token);
        $row = $this->shares->entry($share, $workspace, $principal, $entry);
        abort_unless($row->kind === 'file' && $row->current_version_id !== null, 404);
        abort_if($operation === 'download' && ! $share->allow_download, 403);
        if ($operation !== 'download') {
            $role = $operation;
            if ($row->{$role.'_state'} === 'ready' && $row->{$role.'_version_id'} === $row->current_version_id) {
                $mime = $role === 'thumbnail' ? $this->sources->thumbnailMime($workspace, $principal, $row) : $this->sources->previewMime($workspace, $principal, $row);
                $disk = $row->{$role.'_disk'};
                $key = $row->{$role.'_key'};
                $size = null;
                if ($row->source_kind === 'managed' && $row->{$role.'_preset'} !== null) {
                    $rendition = Renditions::published()->where('entry_id', $row->id)->where('version_id', $row->current_version_id)->where('preset', $row->{$role.'_preset'})->first();
                    abort_if($rendition === null, 404);
                    app(Renditions::class)->assertExternal($workspace, $row, $rendition, $principal);
                    $disk = $rendition->disk;
                    $key = $rendition->object_key;
                    $mime = $rendition->mime_type;
                    $size = $rendition->size_bytes;
                }
                abort_unless(in_array($mime, Renditions::IMAGE_MIMES, true) || ($role === 'preview' && $mime === 'video/mp4'), 404);

                $record = (object) [
                    'disk' => $disk, 'object_key' => $key, 'mime_type' => $mime,
                    'size_bytes' => $size, 'name' => $row->name,
                ];

                return app(MediaResponse::class)->stream($request->duplicate(['download' => false]), $record, DeliveryResource::fromRecord($record, $workspace->public_id, $row->current_version_id, $role === 'preview' && str_starts_with($mime, 'video/') ? 'video' : $role, $row->{$role.'_preset'}, expiresAt: $share->expires_at === null ? null : strtotime($share->expires_at)));
            }
            abort_unless($operation === 'preview' && $this->inline($row->mime_type), 404);
        }
        $version = DB::table('fms_asset_versions')->where('id', $row->current_version_id)->where('entry_id', $row->id)->first();
        abort_if($version === null, 404);
        $record = (object) [...(array) $version, 'id' => $row->id, 'current_version_id' => $version->id, 'source_kind' => $row->source_kind, 'name' => $row->name];
        $this->sources->original($workspace, $principal, $record);

        return app(MediaResponse::class)->stream($request->duplicate(['download' => $operation === 'download']), $record, DeliveryResource::fromRecord($record, $workspace->public_id, $version->id, str_starts_with($record->mime_type, 'video/') && $operation !== 'download' ? 'video' : 'original', download: $operation === 'download', expiresAt: $share->expires_at === null ? null : strtotime($share->expires_at)));
    }

    /** A functional no-JavaScript guest reader; applications may replace it using the public JSON routes. */
    public function show(Request $request, string $token, ShareEngagement $engagement): Response
    {
        [$share, $workspace, $principal] = $this->shares->context($request, $token, password: false);
        $locked = $share->password_hash !== null && (int) $request->session()->get($this->shares->grantKey($share), 0) !== (int) $share->revision;
        $page = $locked ? null : $this->publicPage($request, $token, $share, $workspace, $principal);
        $ticket = null;
        if (! $locked && $page['items'] !== []) {
            try {
                $ticket = $engagement->visit($request, $share);
            } catch (QueryException $error) {
                report($error);
            }
        }
        $nonce = base64_encode(random_bytes(18));
        $origins = implode(' ', app(Delivery::class)->origins());

        return response()->view('media-studio::shared-collection', [
            'share' => $share, 'page' => $page, 'locked' => $locked, 'nonce' => $nonce,
            'collectionUrl' => $this->shares->url($token), 'token' => $token,
            'parent' => $request->query('parent'), 'engagementTicket' => $ticket,
            'guestComments' => $share->recipient_permission === null ? [] : DB::table('fms_share_guest_comments')->where('share_id', $share->id)->orderByDesc('id')->limit(30)->get(),
            'mayCopy' => ! $locked && $request->user() && $share->allow_download && $share->allow_copy && $share->visibility === 'publish',
            'mayRename' => $request->user() && $share->recipient_permission === 'edit',
            'mayComment' => $request->user() && in_array($share->recipient_permission, ['comment', 'edit'], true),
            'copyWorkspaces' => $this->copyWorkspaces($request, $share, $locked),
        ])->withHeaders([
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => ! $locked && $share->visibility === 'publish' && $share->allow_indexing ? 'index, follow' : 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer', 'Content-Security-Policy' => "default-src 'none'; img-src 'self' {$origins}; style-src 'nonce-{$nonce}'; form-action 'self'; script-src 'nonce-{$nonce}'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'",
        ]);
    }

    private function copyWorkspaces(Request $request, object $share, bool $locked): array
    {
        $user = $request->user();
        if (! $user || $locked || ! $share->allow_copy || ! $share->allow_download || $share->visibility !== 'publish') {
            return [];
        }
        $access = app(WorkspaceAccess::class);
        $principal = Principal::fromUser($user);
        $permissions = app(StudioPermissions::class);

        return $access->query($principal)->orderBy('w.id')->limit(100)->get()
            ->filter(fn (object $workspace): bool => in_array($workspace->role, ['owner', 'admin', 'editor'], true)
                && $permissions->allows($user, 'uploads.create', $workspace))
            ->map(fn (object $workspace): array => ['id' => $workspace->public_id, 'name' => $workspace->name])->values()->all();
    }

    private function publicCard(string $token, object $share, object $row): array
    {
        $url = fn (string $operation): string => route('media-studio.shared.content', ['token' => $token, 'entry' => $row->public_id, 'operation' => $operation]);
        $ready = $row->kind === 'file' && $row->current_version_id !== null;
        $thumbnail = $ready && $row->thumbnail_state === 'ready' && $row->thumbnail_version_id === $row->current_version_id;
        $preview = $ready && (($row->preview_state === 'ready' && $row->preview_version_id === $row->current_version_id) || $this->inline($row->mime_type));

        return ['id' => $row->public_id, 'name' => $row->name, 'kind' => $row->kind, 'revision' => (int) $row->revision, 'editable' => $row->source_kind === 'managed', 'mimeType' => $row->mime_type,
            'sizeBytes' => $row->size_bytes === null ? null : (int) $row->size_bytes,
            'thumbnailUrl' => $thumbnail ? $url('thumbnail') : null, 'previewUrl' => $preview ? $url('preview') : null,
            'downloadUrl' => $ready && $share->allow_download ? $url('download') : null];
    }

    private function inline(?string $mime): bool
    {
        return preg_match('~\A(?:image/(?:jpeg|png|webp|avif|gif)|video/[a-z0-9.+-]+|audio/[a-z0-9.+-]+|application/pdf)\z~', $mime ?? '') === 1;
    }

    private function fields(): array
    {
        return ['title' => ['sometimes', 'required', 'string', 'max:160'], 'allowDownload' => ['sometimes', 'boolean'], 'allowArchive' => ['sometimes', 'boolean'], 'allowUpload' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', 'in:share,publish'], 'collectAnalytics' => ['sometimes', 'boolean'], 'allowIndexing' => ['sometimes', 'boolean'], 'allowCopy' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:128'],
            'expiresAt' => ['sometimes', 'nullable', 'date', 'after:now']];
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
