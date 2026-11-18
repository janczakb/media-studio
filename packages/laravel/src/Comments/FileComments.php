<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Comments;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\ActivityLog;
use UseFlex\MediaStudio\Activity\ScopedCursor;
use UseFlex\MediaStudio\Identity\AttributeMemberProfileResolver;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Storage\Encryption\DatabasePayloads;

final class FileComments
{
    public function __construct(private WorkspaceAccess $access, private Entries $entries, private StudioPermissions $permissions, private SourcePolicy $sources) {}

    private function context(Authenticatable $actor, string $workspace, string $entry, string $operation, bool $lock = false): array
    {
        abort_unless(config('media-studio.comments.enabled', true), 404);
        $w = $this->access->resolve(Principal::fromUser($actor), $workspace);
        $this->permissions->authorize($actor, 'comments.view', $w);
        $this->permissions->authorize($actor, $operation, $w);
        $e = $this->entries->resolve($w->id, $entry);
        if ($lock) {
            $e = DB::table('fms_entries')->where('workspace_id', $w->id)->where('id', $e->id)->lockForUpdate()->firstOrFail();
        }
        abort_if($e->trashed_at !== null, 404);
        $this->sources->readable($w, Principal::fromUser($actor), $e);

        return [$w, $e];
    }

    public function index(Authenticatable $actor, string $workspace, string $entry, array $d, ?string $cursorScope = null): array
    {
        [$w,$e] = $this->context($actor, $workspace, $entry, 'comments.view');
        $filter = $d['filter'] ?? 'all';
        $codec = new ScopedCursor(hash('sha256', json_encode([$workspace, $entry, Principal::fromUser($actor)->key(), $filter, $cursorScope, $d['anchorVersion'] ?? null])));
        $q = DB::table('fms_comments')->where('workspace_id', $w->id)->where('entry_id', $e->id);
        if (isset($d['anchorVersion'])) {
            $q->where('anchor_version_id', $d['anchorVersion']);
        }
        if ($filter === 'open') {
            $q->whereNull('resolved_at');
        }
        if ($filter === 'resolved') {
            $q->whereNotNull('resolved_at');
        }
        $page = $q->orderByDesc('id')->cursorPaginate(30, ['*'], 'cursor', $codec->decode($d['cursor'] ?? null));
        $rows = array_map(fn ($row) => app(DatabasePayloads::class)->reveal('fms_comments', $row->public_id, $row), $page->items());
        $profiles = $this->profiles($rows);

        return ['items' => array_map(fn ($c) => $this->payload($c, $actor, $w, $profiles), $rows), 'nextCursor' => $codec->encode($page->nextCursor()), 'hasMore' => $page->hasMorePages(), 'canCreate' => $this->permissions->allows($actor, 'comments.create', $w)];
    }

    public function store(Authenticatable $actor, string $workspace, string $entry, array $d): array
    {
        $d['body'] = trim($d['body']);
        abort_if($d['body'] === '', 422);
        $d['mentions'] = array_values(array_unique($d['mentions'] ?? []));
        $c = DB::transaction(function () use ($actor, $workspace, $entry, $d) {
            [$w,$e] = $this->context($actor, $workspace, $entry, 'comments.create', lock: true);
            $anchor = $d['anchor'] ?? null;
            $p = Principal::fromUser($actor);
            $key = hash('sha256', $workspace.$entry.$p->key().$d['key']);
            $old = DB::table('fms_comments')->where('request_key', $key)->first();
            if ($old) {
                $old = app(DatabasePayloads::class)->reveal('fms_comments', $old->public_id, $old);
            }
            if (isset($d['document'])) {
                $d = array_merge($d, app(CommentDocument::class)->normalize(json_decode($d['document'], true), $w, $old ? json_decode($old->document ?? '[]', true) : []));
            }
            if ($old) {
                abort_unless(($old->anchor_version_id ?? null) === ($anchor['versionId'] ?? null) && ($anchor === null || ((int) $old->anchor_x === $anchor['x'] && (int) $old->anchor_y === $anchor['y'])), 409);
                abort_unless($old->body === $d['body'] && (isset($d['document']) ? json_decode($old->document ?? '[]', true) === $d['document'] : json_decode($old->mentions, true) === $d['mentions']), 409);

                return DB::table('fms_comments')->where('id', $old->id)->first();
            }
            if ($anchor !== null) {
                abort_unless(is_array($anchor) && $e->media_type === 'image' && ($anchor['versionId'] ?? null) === $e->current_version_id && is_int($anchor['x'] ?? null) && is_int($anchor['y'] ?? null) && $anchor['x'] >= 0 && $anchor['x'] <= 1000 && $anchor['y'] >= 0 && $anchor['y'] <= 1000, 422, 'Invalid image comment anchor.');
            }
            $members = $this->recipients($w, $d['mentions']);
            $commentId = (string) Str::ulid();
            $id = DB::table('fms_comments')->insertGetId(app(DatabasePayloads::class)->protect('fms_comments', $commentId, ['public_id' => $commentId, 'workspace_id' => $w->id, 'entry_id' => $e->id, 'anchor_version_id' => $anchor['versionId'] ?? null, 'anchor_x' => $anchor['x'] ?? null, 'anchor_y' => $anchor['y'] ?? null, 'author_key' => $p->key(), 'author_type' => $p->type, 'author_id' => $p->id, 'document' => isset($d['document']) ? json_encode($d['document']) : null, 'body' => $d['body'], 'mentions' => json_encode($d['mentions']), 'request_key' => $key, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]));
            $c = DB::table('fms_comments')->where('id', $id)->first();
            foreach ($members as $m) {
                if ($m->principal_key !== $p->key()) {
                    app(ActivityLog::class)->record($w, $p, $e, 'mentioned', $c->public_id, new Principal($m->principal_type, $m->principal_id));
                }
            }

            return $c;
        }, 3);
        [$w] = $this->context($actor, $workspace, $entry, 'comments.view');

        $c = app(DatabasePayloads::class)->reveal('fms_comments', $c->public_id, $c);

        return $this->payload($c, $actor, $w, $this->profiles([$c]));
    }

    public function update(Authenticatable $actor, string $workspace, string $entry, string $comment, array $d): array
    {
        $c = DB::transaction(function () use ($actor, $workspace, $entry, $comment, $d) {
            [$w,$e] = $this->context($actor, $workspace, $entry, 'comments.view', lock: true);
            $c = DB::table('fms_comments')->where('workspace_id', $w->id)->where('entry_id', $e->id)->where('public_id', $comment)->lockForUpdate()->first();
            abort_if(! $c, 404);
            $c = app(DatabasePayloads::class)->reveal('fms_comments', $c->public_id, $c);
            $p = Principal::fromUser($actor);
            $delete = (bool) ($d['delete'] ?? false);
            $manage = $this->permissions->allows($actor, 'comments.manage', $w);
            if ($delete) {
                abort_unless($manage || ($c->author_key === $p->key() && $this->permissions->allows($actor, 'comments.create', $w)), 403);
            } else {
                if (isset($d['body']) || isset($d['document'])) {
                    abort_unless($manage || ($c->author_key === $p->key() && $this->permissions->allows($actor, 'comments.create', $w)), 403);
                }
                if (array_key_exists('resolved', $d)) {
                    $this->permissions->authorize($actor, 'comments.resolve', $w);
                }
            }
            abort_unless((int) $c->revision === $d['revision'], 409, 'This comment changed. Refresh before saving.');
            if ($delete) {
                DB::table('fms_comments')->where('id', $c->id)->delete();

                return null;
            }
            if (isset($d['document'])) {
                $d = array_merge($d, app(CommentDocument::class)->normalize(json_decode($d['document'], true), $w, json_decode($c->document ?? '[]', true)));
            }
            $values = ['revision' => $c->revision + 1, 'updated_at' => now()];
            if (isset($d['body']) || isset($d['document'])) {
                $body = trim($d['body']);
                abort_if($body === '', 422);
                $mentions = $d['mentions'] ?? [];
                $members = $this->recipients($w, $mentions);
                $old = json_decode($c->mentions, true);
                $values['body'] = $body;
                $values['document'] = isset($d['document']) ? json_encode($d['document']) : null;
                $values['mentions'] = json_encode($mentions);
                foreach ($members as $m) {
                    if (! in_array((string) $m->id, $old, true) && $m->principal_key !== $p->key()) {
                        app(ActivityLog::class)->record($w, $p, $e, 'mentioned', $c->public_id.':'.($c->revision + 1), new Principal($m->principal_type, $m->principal_id));
                    }
                }
            }
            if (array_key_exists('resolved', $d)) {
                $values['resolved_at'] = $d['resolved'] ? now() : null;
            }
            DB::table('fms_comments')->where('id', $c->id)->update(app(DatabasePayloads::class)->protect('fms_comments', $c->public_id, $values));

            return DB::table('fms_comments')->where('id', $c->id)->first();
        }, 3);
        [$w] = $this->context($actor, $workspace, $entry, 'comments.view');

        if ($c) {
            $c = app(DatabasePayloads::class)->reveal('fms_comments', $c->public_id, $c);
        }

        return $c ? $this->payload($c, $actor, $w, $this->profiles([$c])) : ['deleted' => true];
    }

    public function mentions(Authenticatable $actor, string $workspace, string $entry, array $d): array
    {
        [$w] = $this->context($actor, $workspace, $entry, 'comments.create');
        $items = [];
        $column = config('media-studio.members.search_attribute', 'name');
        abort_unless(is_string($column) && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $column), 422);
        $resolver = app(config('media-studio.member_profiles.resolver', AttributeMemberProfileResolver::class));
        foreach (config('media-studio.principals', []) as $type => $class) {
            if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            $model = new $class;
            $qualified = $model->qualifyColumn($model->getAuthIdentifierName());
            $cast = DB::connection()->getDriverName() === 'mysql' ? 'CHAR' : 'TEXT';
            $users = $model->newQuery()->where($model->qualifyColumn($column), 'like', addcslashes(trim($d['search'] ?? ''), '%_\\').'%')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('fms_memberships as cm')->where('cm.workspace_id', $w->id)->where('cm.principal_type', $type)->whereRaw('cm.principal_id = CAST('.DB::connection()->getQueryGrammar()->wrap($qualified).' AS '.$cast.')'))
                ->orderBy($model->getAuthIdentifierName())->limit(20)->get();
            $members = DB::table('fms_memberships')->where('workspace_id', $w->id)->where('principal_type', $type)->whereIn('principal_id', $users->map(fn ($u) => (string) $u->getAuthIdentifier())->all())->get()->keyBy('principal_id');
            foreach ($users as $u) {
                $m = $members[(string) $u->getAuthIdentifier()] ?? null;
                if ($m) {
                    $profile = $resolver->resolve($u);
                    $items[] = ['id' => (string) $u->getAuthIdentifier(), 'principalType' => $type, 'memberId' => (string) $m->id, 'name' => $profile['name'], 'avatarUrl' => $profile['avatarUrl']];
                }
                if (count($items) >= 20) {
                    break 2;
                }
            }
        }

        return ['items' => $items];
    }

    private function recipients(object $w, array $ids): array
    {
        $rows = DB::table('fms_memberships')->where('workspace_id', $w->id)->whereIn('id', $ids)->get()->all();
        abort_unless(count($rows) === count($ids), 422, 'Mentioned person is no longer a team member.');

        return $rows;
    }

    private function profiles(array $rows): array
    {
        $subjects = $rows;
        foreach ($rows as $row) {
            foreach (json_decode($row->document ?? '[]', true) as $run) {
                if (($run['type'] ?? null) === 'mention') {
                    $p = new Principal($run['principalType'], $run['id']);
                    $subjects[] = (object) ['author_type' => $p->type, 'author_id' => $p->id, 'author_key' => $p->key()];
                }
            }
        }
        $rows = $subjects;
        $profiles = [];
        $resolver = app(config('media-studio.member_profiles.resolver', AttributeMemberProfileResolver::class));
        foreach (array_unique(array_column($rows, 'author_type')) as $type) {
            $class = config('media-studio.principals.'.$type);
            if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            $ids = array_map(fn ($r) => $r->author_id, array_filter($rows, fn ($r) => $r->author_type === $type));
            $model = new $class;
            foreach ($model->newQuery()->whereIn($model->getAuthIdentifierName(), $ids)->get() as $u) {
                $profiles[Principal::fromUser($u)->key()] = $resolver->resolve($u);
            }
        }

        return $profiles;
    }

    private function payload(object $c, Authenticatable $actor, object $w, array $profiles): array
    {
        $profile = $profiles[$c->author_key] ?? null;
        $document = json_decode($c->document ?? 'null', true);
        if (is_array($document)) {
            foreach ($document as &$run) {
                if ($run['type'] === 'mention') {
                    $key = (new Principal($run['principalType'], $run['id']))->key();
                    $run['name'] = $profiles[$key]['name'] ?? null;
                }
            }
        }
        unset($run);
        $displayBody = is_array($document) ? implode('', array_map(fn ($run) => $run['type'] === 'mention' ? '@'.($run['name'] ?? 'Team member') : $run['text'], $document)) : $c->body;
        $own = $c->author_key === Principal::fromUser($actor)->key();
        if ($own && $profile === null) {
            $profile = app(config('media-studio.member_profiles.resolver', AttributeMemberProfileResolver::class))->resolve($actor);
        }
        $profile = $profile === null ? null : ['name' => $profile['name'], 'avatarUrl' => $profile['avatarUrl']];
        $manage = $this->permissions->allows($actor, 'comments.manage', $w);

        return ['anchor' => isset($c->anchor_version_id) ? ['versionId' => $c->anchor_version_id, 'x' => (int) $c->anchor_x, 'y' => (int) $c->anchor_y] : null, 'id' => $c->public_id, 'body' => $displayBody, 'document' => $document, 'mentions' => json_decode($c->mentions, true), 'revision' => (int) $c->revision, 'author' => $profile ?? ['name' => null, 'avatarUrl' => null], 'createdAt' => Carbon::parse($c->created_at, 'UTC')->toISOString(), 'updatedAt' => Carbon::parse($c->updated_at, 'UTC')->toISOString(), 'resolved' => $c->resolved_at !== null, 'canEdit' => $manage || ($own && $this->permissions->allows($actor, 'comments.create', $w)), 'canResolve' => $this->permissions->allows($actor, 'comments.resolve', $w)];
    }
}
