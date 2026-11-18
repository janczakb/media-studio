<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\AttributeMemberProfileResolver;
use UseFlex\MediaStudio\Identity\MemberProfileResolver;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Members\MemberAccess;
use UseFlex\MediaStudio\Members\MemberDirectory;
use UseFlex\MediaStudio\Members\WorkspaceMemberSearch;

final class WorkspaceMembersController
{
    public function __construct(private WorkspaceAccess $access, private MemberAccess $members) {}

    public function index(Request $request, string $workspace): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $resolved = $this->access->resolve($principal, $workspace);
        abort_unless($this->members->allows($request, $resolved, 'view'), 403);
        $canManage = $this->members->allows($request, $resolved, 'manage');
        $data = $request->validate(['cursor' => ['nullable', 'string', 'max:4096'], 'search' => ['nullable', 'string', 'max:100']]);
        $search = trim($data['search'] ?? '');
        $after = 0;
        if (isset($data['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($data['cursor']), true, 8, JSON_THROW_ON_ERROR);
                abort_unless(is_array($cursor) && ($cursor['workspace'] ?? null) === $workspace && ($cursor['principal'] ?? null) === $principal->key() && ($cursor['search'] ?? '') === $search && ($cursor['expires'] ?? 0) > time() && is_int($cursor['after'] ?? null) && $cursor['after'] > 0, 422);
                $after = $cursor['after'];
            } catch (DecryptException|\JsonException $e) {
                abort(422, 'Invalid cursor.');
            }
        }
        $query = DB::table('fms_memberships')->where('workspace_id', $resolved->id)->where('id', '>', $after);
        if ($search !== '') {
            app(WorkspaceMemberSearch::class)->apply($query, $principal, $request->user(), $search);
        }
        $rows = $query->orderBy('id')->limit(26)->get();
        $more = $rows->count() > 25;
        $rows = $rows->take(25);
        $models = [];
        foreach ($rows->groupBy('principal_type') as $type => $group) {
            $class = config('media-studio.principals.'.$type);
            if (! is_string($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Authenticatable::class)) {
                continue;
            }
            $model = new $class;
            foreach ($model->newQuery()->whereIn($model->getAuthIdentifierName(), $group->pluck('principal_id')->all())->get() as $user) {
                $models[$type][(string) $user->getAuthIdentifier()] = $user;
            }
        }
        $profileResolver = app(config('media-studio.member_profiles.resolver', AttributeMemberProfileResolver::class));
        if (! $profileResolver instanceof MemberProfileResolver) {
            throw new \LogicException('Member profile resolver must implement MemberProfileResolver.');
        }
        $currentUser = $request->user();
        $items = $rows->map(function ($row) use ($models, $principal, $profileResolver, $currentUser, $canManage, $resolved): array {
            $user = $models[$row->principal_type][$row->principal_id] ?? null;
            if ($user === null && $row->principal_key === $principal->key()) {
                $user = $currentUser;
            }
            $profile = $user === null ? ['name' => null, 'email' => null, 'avatarUrl' => null] : $profileResolver->resolve($user);

            return ['id' => (string) $row->id, ...$profile,
                'role' => $row->role, 'canRemove' => $canManage && $row->role !== 'owner' && ($row->role !== 'admin' || $resolved->role === 'owner') && $row->principal_key !== $principal->key()];
        })->values()->all();
        $last = $rows->last();
        $next = $more && $last ? Crypt::encryptString(json_encode(['workspace' => $workspace, 'principal' => $principal->key(), 'search' => $search, 'after' => (int) $last->id, 'expires' => time() + 3600], JSON_THROW_ON_ERROR)) : null;

        return response()->json(['items' => $items, 'nextCursor' => $next, 'hasMore' => $more])->header('Cache-Control', 'private, no-store');
    }

    public function creationCandidates(Request $request, string $workspace, MemberDirectory $directory): JsonResponse
    {
        abort_unless(config('media-studio.workspaces.enabled', true) && config('media-studio.workspace_creation'), 403);
        app(StudioPermissions::class)->authorize($request->user(), 'workspaces.create');
        $w = $this->access->resolve(Principal::fromUser($request->user()), $workspace);

        return $this->searchCandidates($request, $w, $directory);
    }

    public function candidates(Request $request, string $workspace, MemberDirectory $directory): JsonResponse
    {
        $w = $this->members->resolve($request, $workspace, 'invite');

        return $this->searchCandidates($request, $w, $directory);
    }

    private function searchCandidates(Request $request, object $w, MemberDirectory $directory): JsonResponse
    {
        $request->merge(['search' => is_string($request->input('search')) ? trim($request->input('search')) : $request->input('search')]);
        $data = $request->validate(['search' => ['required', 'string', 'min:3', 'max:100']]);
        $query = $directory->query($request, $w);
        $column = config('media-studio.members.search_attribute', 'name');
        abort_unless(is_string($column) && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $column), 422);
        $search = addcslashes(trim($data['search']), '%_\\');
        $users = $query->where($column, 'like', $search.'%')->orderBy($query->getModel()->getKeyName())->limit(15)->get();
        $resolver = app(config('media-studio.member_profiles.resolver', AttributeMemberProfileResolver::class));

        return response()->json(['items' => $users->map(fn ($user) => ['id' => (string) $user->getAuthIdentifier(), 'type' => Principal::fromUser($user)->type, ...$resolver->resolve($user)])->all()])->header('Cache-Control', 'private, no-store');
    }

    public function assign(Request $request, string $workspace, MemberDirectory $directory): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'string', 'max:64'], 'id' => ['required', 'string', 'max:191'], 'role' => ['required', 'in:admin,editor,viewer']]);
        DB::transaction(function () use ($request, $workspace, $directory, $data): void {
            $w = $this->members->resolve($request, $workspace, 'invite', lock: true);
            $this->members->authorizeRole($w, $data['role']);
            $query = $directory->query($request, $w);
            $user = $query->where($query->getModel()->getAuthIdentifierName(), $data['id'])->first();
            abort_if($user === null, 404);
            $principal = Principal::fromUser($user);
            abort_unless($principal->type === $data['type'], 422);
            $existing = DB::table('fms_memberships')->where('workspace_id', $w->id)->where('principal_key', $principal->key())->first();
            if ($existing !== null) {
                abort_unless($existing->role === $data['role'], 409, 'This user already has a different workspace role.');

                return;
            }
            DB::table('fms_memberships')->insert(['workspace_id' => $w->id, 'principal_type' => $principal->type, 'principal_id' => $principal->id, 'principal_key' => $principal->key(), 'role' => $data['role']]);
        }, 3);

        return response()->json(['added' => true], 201);
    }

    public function update(Request $request, string $workspace, string $member): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'in:admin,editor,viewer']]);
        DB::transaction(function () use ($request, $workspace, $member, $data): void {
            $w = $this->members->resolve($request, $workspace, 'manage', lock: true);
            $this->members->authorizeRole($w, $data['role']);
            $row = DB::table('fms_memberships')->where('workspace_id', $w->id)->where('id', $member)->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_if($row->role === 'owner' || $row->principal_key === Principal::fromUser($request->user())->key(), 403);
            $this->members->authorizeRole($w, $row->role);
            DB::table('fms_memberships')->where('id', $row->id)->update(['role' => $data['role']]);
        }, 3);

        return response()->json(['updated' => true]);
    }

    public function remove(Request $request, string $workspace, string $member): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        DB::transaction(function () use ($principal, $workspace, $member, $request): void {
            $resolved = $this->access->resolve($principal, $workspace, lock: true);
            abort_unless($this->members->allows($request, $resolved, 'manage'), 403);
            $row = DB::table('fms_memberships')->where('workspace_id', $resolved->id)->where('id', $member)->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_if($row->role === 'owner' || $row->principal_key === $principal->key(), 403);
            $this->members->authorizeRole($resolved, $row->role);
            DB::table('fms_memberships')->where('workspace_id', $resolved->id)->where('id', $member)->delete();
        }, 3);

        return response()->json(['removed' => true]);
    }
}
