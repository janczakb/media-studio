<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sharing;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\ScopedCursor;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

/** A share delegates a bounded tree of live entries, never a persistent storage URL. */
final class Shares
{
    public function __construct(private WorkspaceAccess $access, private SourcePolicy $sources) {}

    public function enabled(): void
    {
        abort_unless(config('media-studio.sharing.enabled', true), 404);
    }

    public function workspace(Request $request, string $id, bool $write = false, bool $lock = false): array
    {
        $this->enabled();
        $principal = Principal::fromUser($request->user());
        $workspace = $this->access->resolve($principal, $id, $write, $lock);
        app(StudioPermissions::class)->authorize($request->user(), $write ? 'shares.manage' : 'shares.view', $workspace);

        return [$workspace, $principal];
    }

    public function record(object $workspace, string $id): object
    {
        $share = DB::table('fms_shares')->where('workspace_id', $workspace->id)->where('public_id', $id)->first();
        abort_if($share === null, 404);

        return $share;
    }

    public function canEdit(object $workspace, Principal $principal, object $share): bool
    {
        return in_array($workspace->role, ['owner', 'admin'], true) || ($workspace->role === 'editor' && hash_equals($share->creator_key, $principal->key()));
    }

    public function editable(object $workspace, Principal $principal, object $share, int $revision): void
    {
        abort_unless($this->canEdit($workspace, $principal, $share), 403);
        abort_unless((int) $share->revision === $revision, 409, 'This share changed. Refresh before continuing.');
    }

    public function card(object $workspace, Principal $principal, object $share): array
    {
        return [
            'id' => $share->public_id, 'title' => $share->title, 'kind' => $share->kind,
            'allowDownload' => (bool) $share->allow_download, 'allowArchive' => (bool) $share->allow_archive, 'allowUpload' => (bool) $share->allow_upload, 'passwordProtected' => $share->password_hash !== null,
            'expiresAt' => $share->expires_at === null ? null : Carbon::parse($share->expires_at, 'UTC')->toIso8601String(), 'revokedAt' => $this->date($share->revoked_at),
            'createdAt' => $this->date($share->created_at), 'revision' => (int) $share->revision,
            'recipientEmail' => $this->canEdit($workspace, $principal, $share) ? $share->recipient_email : null,
            'recipientPermission' => $share->recipient_permission, 'visibility' => $share->visibility, 'collectAnalytics' => (bool) $share->collect_analytics,
            'allowCopy' => (bool) $share->allow_copy, 'allowIndexing' => (bool) $share->allow_indexing, 'hasRecoverableUrl' => $share->encrypted_token !== null,
            'entryCount' => (int) $share->entry_count, 'ownedByMe' => hash_equals($share->creator_key, $principal->key()),
            'canEdit' => $this->canEdit($workspace, $principal, $share),
        ];
    }

    public function create(object $workspace, Principal $principal, array $data): array
    {
        $maximum = max(1, min(10000, (int) config('media-studio.sharing.max_links', 1000)));
        abort_if(DB::table('fms_shares')->where('workspace_id', $workspace->id)->count() >= $maximum, 422, 'This workspace has reached its share limit.');
        $rows = DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereIn('public_id', $data['entryIds'])->whereNull('trashed_at')->get();
        abort_unless($rows->count() === count($data['entryIds']), 404);
        abort_unless(count($this->accessible($workspace, $principal, $rows->all())) === $rows->count(), 404);
        if ($data['allowUpload'] ?? false) {
            abort_unless(config('media-studio.sharing.guest_upload_enabled', false) && $rows->count() === 1 && $rows->first()->kind === 'folder' && $rows->first()->source_kind === 'managed', 422, 'Guest uploads require one managed folder.');
        }
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::ulid();
        $shareId = DB::table('fms_shares')->insertGetId([
            'public_id' => $id, 'workspace_id' => $workspace->id,
            'creator_type' => $principal->type, 'creator_id' => $principal->id, 'creator_key' => $principal->key(),
            'title' => $data['title'], 'kind' => $rows->count() === 1 ? $rows->first()->kind : 'selection',
            'token_hash' => hash('sha256', $token), 'encrypted_token' => Crypt::encryptString($token),
            'recipient_email' => $data['recipientEmail'] ?? null, 'recipient_permission' => ($data['visibility'] ?? 'share') === 'share' ? ($data['recipientPermission'] ?? null) : null,
            'visibility' => $data['visibility'] ?? 'share', 'collect_analytics' => $data['collectAnalytics'] ?? false,
            'allow_copy' => ($data['visibility'] ?? 'share') === 'publish' && ($data['allowDownload'] ?? false) && ($data['allowCopy'] ?? false),
            'allow_indexing' => ($data['visibility'] ?? 'share') === 'publish' && ($data['allowIndexing'] ?? false) && ! isset($data['password']),
            'allow_download' => $data['allowDownload'] ?? false,
            'allow_archive' => ($data['allowDownload'] ?? false) && ($data['allowArchive'] ?? false),
            'allow_upload' => $data['allowUpload'] ?? false,
            'password_hash' => isset($data['password']) ? self::hashPassword($data['password']) : null,
            'expires_at' => isset($data['expiresAt']) ? Carbon::parse($data['expiresAt'])->utc() : null,
            'entry_count' => $rows->count(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $map = $rows->keyBy('public_id');
        DB::table('fms_share_entries')->insert(array_map(fn (string $entry, int $position): array => [
            'share_id' => $shareId, 'entry_id' => $map[$entry]->id, 'position' => $position,
        ], $data['entryIds'], array_keys($data['entryIds'])));

        return [...$this->card($workspace, $principal, $this->record($workspace, $id)), 'url' => $this->url($token)];
    }

    /** Recheck the creating principal and tenant on every guest request, including every range request. */
    public function context(Request $request, string $token, bool $password = true): array
    {
        $this->enabled();
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $token), 404);
        $share = DB::table('fms_shares')->where('token_hash', hash('sha256', $token))->first();
        abort_if($share === null || $share->revoked_at !== null || ($share->expires_at !== null && Carbon::parse($share->expires_at, 'UTC')->lte(now())), 404);
        // Also protects links created before atomic trash/share invalidation was installed.
        abort_if(DB::table('fms_share_entries as se')->join('fms_entries as e', 'e.id', '=', 'se.entry_id')
            ->where('se.share_id', $share->id)->whereNotNull('e.trashed_at')->exists(), 404);
        if ($share->recipient_email !== null) {
            $user = $request->user();
            $verifiedEmail = $user && method_exists($user, 'hasVerifiedEmail') && method_exists($user, 'getEmailForVerification') && $user->hasVerifiedEmail() ? $user->getEmailForVerification() : null;
            abort_unless(is_string($verifiedEmail) && hash_equals($share->recipient_email, mb_strtolower(trim($verifiedEmail))), 403);
        }
        $raw = DB::table('fms_workspaces')->where('id', $share->workspace_id)->first();
        abort_if($raw === null, 404);
        $principal = new Principal($share->creator_type, $share->creator_id);
        $workspace = $this->access->resolveForWorker($principal, $raw->public_id, TenantIdentity::fromWorkspace($raw), write: true, operation: 'shares.manage');
        abort_unless(hash_equals($share->creator_key, $principal->key()) && config('media-studio.principals.'.$principal->type) !== null, 404);
        if ($password && $share->password_hash !== null) {
            abort_unless($request->hasSession() && (int) $request->session()->get($this->grantKey($share), 0) === (int) $share->revision, 423, 'Enter the share password to continue.');
        }

        // A link delegates existing abilities; it must not restore an ability its creator lost.
        $permissions = app(StudioPermissions::class);
        $actor = $permissions->actor($principal);
        $share->allow_download = $share->allow_download && $permissions->allows($actor, 'files.download', $workspace);
        $share->allow_archive = $share->allow_archive && $share->allow_download;
        $share->allow_upload = $share->allow_upload && config('media-studio.sharing.guest_upload_enabled', false)
            && $permissions->allows($actor, 'uploads.create', $workspace);

        return [$share, $workspace, $principal];
    }

    public function entry(object $share, object $workspace, Principal $principal, string $id): object
    {
        $row = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('public_id', $id)->whereNull('trashed_at')->first();
        abort_if($row === null, 404);
        $roots = DB::table('fms_share_entries')->where('share_id', $share->id)->pluck('entry_id')->all();
        abort_unless($this->accessible($workspace, $principal, [$row], $roots) !== [], 404);

        return $row;
    }

    /** Resolve each ancestor level in one query, then evaluate the complete chain for every row. */
    public function accessible(object $workspace, Principal $principal, array $rows, ?array $roots = null): array
    {
        $entries = array_column($rows, null, 'id');
        $frontier = $rows;
        for ($level = 0; $level < 64 && $frontier !== []; $level++) {
            $parentIds = [];
            foreach ($frontier as $row) {
                if ($row->parent_id !== null && ! array_key_exists($row->parent_id, $entries)) {
                    $parentIds[$row->parent_id] = $row->parent_id;
                }
            }
            if ($parentIds === []) {
                break;
            }
            $frontier = DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereIn('id', array_values($parentIds))
                ->where('kind', 'folder')->whereNull('trashed_at')->get(['id', 'parent_id', 'source_kind'])->all();
            $entries += array_column($frontier, null, 'id');
        }
        $rootIds = array_fill_keys($roots ?? [], true);
        $visible = [];
        foreach (array_chunk($entries, 120) as $batch) {
            $visible += array_column($this->sources->visible($workspace, $principal, $batch), null, 'id');
        }

        return array_values(array_filter($rows, static function (object $row) use ($visible, $roots, $rootIds): bool {
            $inShare = $roots === null;
            $seen = [];
            $id = $row->id;
            while ($id !== null) {
                if (! isset($visible[$id]) || isset($seen[$id]) || count($seen) > 64) {
                    return false;
                }
                $seen[$id] = true;
                $inShare = $inShare || isset($rootIds[$id]);
                $id = $visible[$id]->parent_id;
            }

            return $inShare;
        }));
    }

    public function page(object $share, object $workspace, Principal $principal, ?string $parent, int $limit, ?string $cursor): array
    {
        $query = DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereNull('trashed_at');
        if ($parent !== null) {
            $folder = $this->entry($share, $workspace, $principal, $parent);
            abort_unless($folder->kind === 'folder', 404);
            $query->where('parent_scope', $folder->id)->where('parent_id', $folder->id);
        } else {
            $query->whereIn('id', DB::table('fms_share_entries')->select('entry_id')->where('share_id', $share->id));
        }
        $scope = new ScopedCursor('share:'.$share->public_id.':'.$share->revision.':'.($parent ?? 'root'));
        $page = $query->orderBy('sort_group')->orderBy('normalized_name')->orderBy('id')->cursorPaginate($limit, ['*'], 'cursor', $scope->decode($cursor));
        // The folder's complete chain is already checked. Root selections may have different parents.
        $items = $parent === null
            ? $this->accessible($workspace, $principal, $page->items())
            : $this->sources->visible($workspace, $principal, $page->items());

        return ['items' => $items, 'nextCursor' => $scope->encode($page->nextCursor()), 'previousCursor' => $scope->encode($page->previousCursor()), 'hasMore' => $page->hasMorePages()];
    }

    public static function hashPassword(string $password): string
    {
        return Hash::make(hash('sha256', $password));
    }

    public static function checkPassword(string $password, string $digest): bool
    {
        return Hash::check(hash('sha256', $password), $digest);
    }

    public function url(string $token): string
    {
        return route('media-studio.shared.show', ['token' => $token]);
    }

    public function grantKey(object $share): string
    {
        return 'media-studio.share.'.$share->public_id;
    }

    public function date(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->toIso8601String();
    }
}
