<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Trash;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\TrustedClientContext;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Activity\ActivityLog;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Navigation\NavigationChanges;

/** Durable preflight; the final SQL transition is atomic and never touches storage. */
final class TreeOperations
{
    public function __construct(private WorkspaceAccess $access, private Entries $entries) {}

    public function required(string $workspace, array $items): bool
    {
        return DB::table('fms_entries')->where('workspace_id', DB::table('fms_workspaces')->select('id')->where('public_id', $workspace))
            ->whereIn('public_id', array_column($items, 'id'))->where(fn ($q) => $q->where('kind', 'folder')->orWhereNotNull('trash_group_id'))->exists();
    }

    public function admit(Principal $principal, string $workspaceId, string $kind, array $items, string $key): array
    {
        abort_unless(in_array($kind, ['trash', 'restore'], true) && count($items) >= 1 && count($items) <= 100, 422);
        abort_unless(array_is_list($items), 422);
        foreach ($items as $item) {
            abort_unless(is_array($item) && is_string($item['id'] ?? null) && Str::isUlid($item['id']) && is_int($item['revision'] ?? null) && $item['revision'] > 0, 422);
        }
        $items = array_map(static fn (array $item): array => ['id' => strtoupper($item['id']), 'revision' => (int) $item['revision']], $items);
        abort_unless(strlen($key) >= 16 && strlen($key) <= 64, 422);
        $hash = hash('sha256', $principal->key());
        $fingerprint = hash('sha256', json_encode([$kind, $items], JSON_THROW_ON_ERROR));
        $w = $this->access->resolve($principal, $workspaceId);
        $existing = DB::table('fms_tree_operations')->where('workspace_id', $w->id)->where('principal_hash', $hash)->where('request_key', $key)->first();
        if ($existing !== null) {
            abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409, 'The operation key was already used.');

            return $this->status($principal, $workspaceId, $existing->id);
        }
        $id = DB::transaction(function () use ($principal, $workspaceId, $kind, $items, $key, $hash, $fingerprint): string {
            $workspace = $this->access->resolve($principal, $workspaceId, write: true, lock: true);
            $this->authorize($principal, $workspace, $kind);
            $roots = [];
            foreach ($items as $item) {
                $row = $this->entries->resolve($workspace->id, strtoupper($item['id']));
                app(SourcePolicy::class)->managed($row);
                abort_unless((int) $row->revision === (int) $item['revision'], 409, 'An item changed. Refresh before retrying.');
                abort_unless($kind === 'trash' ? $row->trashed_at === null : $row->trashed_at !== null, 409, 'The item state changed.');
                abort_if(isset($roots[$row->id]), 422, 'Duplicate operation root.');
                $roots[$row->id] = $row;
            }
            $id = (string) Str::ulid();
            DB::table('fms_tree_operations')->insert(['id' => $id, 'workspace_id' => $workspace->id,
                'principal_type' => $principal->type, 'principal_id' => $principal->id, 'principal_hash' => $hash,
                'request_key' => $key, 'fingerprint' => $fingerprint, 'operation' => $kind, 'state' => 'preparing',
                'roots' => json_encode(array_column($items, 'id'), JSON_THROW_ON_ERROR), 'discovered' => count($roots), 'examined' => 0,
                'created_at' => now(), 'updated_at' => now()]);
            foreach ($roots as $row) {
                DB::table('fms_tree_operation_nodes')->insert(['operation_id' => $id, 'entry_id' => $row->id, 'revision' => $row->revision, 'group_id' => $row->trash_group_id]);
            }
            DB::table('fms_workspaces')->where('id', $workspace->id)->update(['tree_operation_id' => $id]);
            app(NavigationChanges::class)->record((int) $workspace->id, 'invalidate', ['operationId' => $id]);

            return $id;
        }, 3);

        return $this->status($principal, $workspaceId, $id);
    }

    public function status(Principal $principal, string $workspaceId, string $id): array
    {
        [$workspace, $operation] = $this->resolve($principal, $workspaceId, $id);
        $roots = json_decode($operation->roots, true, 8, JSON_THROW_ON_ERROR);
        $items = $operation->state === 'completed'
            ? DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereIn('public_id', $roots)->get()->map(function ($row) use ($workspace) {
                $row->workspace_public_id = $workspace->public_id;

                return $this->entries->card($row);
            })->all() : [];

        return ['treeOperation' => ['id' => $id, 'state' => $operation->state, 'operation' => $operation->operation,
            'discovered' => (int) $operation->discovered, 'examined' => (int) $operation->examined, 'error' => $operation->error], 'items' => $items];
    }

    public function advance(Principal $principal, string $workspaceId, string $id): array
    {
        $this->resolve($principal, $workspaceId, $id);
        try {
            DB::transaction(function () use ($principal, $workspaceId, $id): void {
                [$workspace, $operation] = $this->resolve($principal, $workspaceId, $id, true);
                if ($operation->state !== 'preparing') {
                    return;
                }
                abort_unless($workspace->tree_operation_id === $id, 409, 'The operation lock changed.');
                $budget = min(500, max(10, (int) config('media-studio.trash.tree_batch_size', 200)));
                $deadline = microtime(true) + .35;
                while ($budget > 0 && microtime(true) < $deadline) {
                    $node = DB::table('fms_tree_operation_nodes')->where('operation_id', $id)->where('expanded', false)->orderBy('entry_id')->first();
                    if ($node === null) {
                        $this->commit($workspace, $operation, $principal);

                        return;
                    }
                    $entry = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $node->entry_id)->first();
                    abort_if($entry === null, 409, 'An operation item disappeared.');
                    $this->check($entry, $node, $operation->operation);
                    $budget--;
                    $children = collect();
                    if ($entry->kind === 'folder' && ($operation->operation === 'trash' || $node->group_id !== null)) {
                        $children = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('parent_scope', $entry->id)->where('id', '>', $node->after_id)
                            ->when($operation->operation === 'trash', fn ($q) => $q->whereNull('trashed_at'), fn ($q) => $q->whereNotNull('trashed_at')->where('trash_group_id', $node->group_id))
                            ->orderBy('id')->limit(max(1, $budget))->get(['id', 'revision', 'trash_group_id']);
                    }
                    $inserted = $children->isEmpty() ? 0 : DB::table('fms_tree_operation_nodes')->insertOrIgnore($children->map(fn ($child) => [
                        'operation_id' => $id, 'entry_id' => $child->id, 'revision' => $child->revision, 'group_id' => $child->trash_group_id,
                    ])->all());
                    DB::table('fms_tree_operation_nodes')->where('operation_id', $id)->where('entry_id', $entry->id)->update([
                        'after_id' => $children->last()?->id ?? $node->after_id, 'expanded' => $children->isEmpty(),
                    ]);
                    DB::table('fms_tree_operations')->where('id', $id)->increment('discovered', $inserted, ['updated_at' => now()]);
                    if ($children->isEmpty()) {
                        DB::table('fms_tree_operations')->where('id', $id)->increment('examined');
                    }
                    $budget -= $children->count();
                }
            }, 3);
        } catch (HttpExceptionInterface $error) {
            // Permission/tenant failure must not expose operation state to a different actor.
            $this->finishFailure($principal, $workspaceId, $id, $error->getMessage());
        }

        return $this->status($principal, $workspaceId, $id);
    }

    /** Trusted maintenance entry point, never accepts a client-provided tenant context. */
    public function advanceSaved(string $id): void
    {
        $operation = DB::table('fms_tree_operations')->where('id', $id)->where('state', 'preparing')->first();
        if ($operation === null) {
            return;
        }
        $workspace = DB::table('fms_workspaces')->where('id', $operation->workspace_id)->first();
        if ($workspace === null) {
            return;
        }
        $principal = new Principal($operation->principal_type, $operation->principal_id);
        $attribute = TrustedClientContext::ATTRIBUTE;
        $previous = request()->attributes->get($attribute);
        request()->attributes->set($attribute, new TrustedClientContext($principal, TenantIdentity::fromWorkspace($workspace)));
        try {
            $this->advance($principal, $workspace->public_id, $id);
        } catch (HttpExceptionInterface $error) {
            // Revocation must release a durable fence, but never commit the forbidden mutation.
            DB::transaction(function () use ($workspace, $id): void {
                $locked = DB::table('fms_workspaces')->where('id', $workspace->id)->lockForUpdate()->first();
                if ($locked?->tree_operation_id !== $id) {
                    return;
                }
                DB::table('fms_tree_operations')->where('id', $id)->where('state', 'preparing')->update(['state' => 'failed', 'error' => 'Operation authorization changed.', 'updated_at' => now()]);
                $this->unlock($locked, $id);
            }, 3);
        } finally {
            if ($previous === null) {
                request()->attributes->remove($attribute);
            } else {
                request()->attributes->set($attribute, $previous);
            }
        }
    }

    public function cancel(Principal $principal, string $workspaceId, string $id): array
    {
        DB::transaction(function () use ($principal, $workspaceId, $id): void {
            [$workspace, $operation] = $this->resolve($principal, $workspaceId, $id, true);
            if ($operation->state !== 'preparing') {
                return;
            }
            DB::table('fms_tree_operations')->where('id', $id)->update(['state' => 'cancelled', 'updated_at' => now()]);
            $this->unlock($workspace, $id);
        }, 3);

        return $this->status($principal, $workspaceId, $id);
    }

    private function resolve(Principal $principal, string $workspaceId, string $id, bool $lock = false): array
    {
        $workspace = $this->access->resolve($principal, $workspaceId, lock: $lock);
        $operation = DB::table('fms_tree_operations')->where('workspace_id', $workspace->id)->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_if($operation === null || (! hash_equals($operation->principal_hash, hash('sha256', $principal->key())) && ! in_array($workspace->role, ['owner', 'admin'], true)), 404);
        abort_if(($workspace->retirement_id ?? null) !== null || ($workspace->encryption_operation ?? null) !== null, 423);
        $this->authorize($principal, $workspace, $operation->operation);

        return [$workspace, $operation];
    }

    private function authorize(Principal $principal, object $workspace, string $kind): void
    {
        abort_unless(in_array($workspace->role, ['owner', 'admin', 'editor'], true), 403);
        app(StudioPermissions::class)->authorize(app(StudioPermissions::class)->actor($principal), $kind === 'trash' ? 'files.trash' : 'files.restore', $workspace);
    }

    private function check(object $entry, object $node, string $kind): void
    {
        app(SourcePolicy::class)->managed($entry);
        abort_unless((int) $entry->revision === (int) $node->revision, 409, 'An operation item changed.');
        if ($kind === 'trash') {
            abort_if($entry->trashed_at !== null || DB::table('fms_usages')->where('entry_id', $entry->id)->exists(), 409, 'A folder contains an unavailable or application-referenced item.');
        } else {
            abort_if($entry->trashed_at === null || $entry->trash_group_id !== $node->group_id, 409, 'A trash item changed.');
            abort_if($node->group_id !== null && DB::table('fms_tree_operations')->where('id', $node->group_id)->whereNotNull('purging_at')->exists(), 409, 'Permanent cleanup of this group has already started.');
        }
    }

    private function commit(object $workspace, object $operation, Principal $principal): void
    {
        $id = $operation->id;
        $members = DB::table('fms_tree_operation_nodes')->where('operation_id', $id)->select('entry_id');
        $scope = DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereIn('id', $members);
        abort_unless((clone $scope)->count() === DB::table('fms_tree_operation_nodes')->where('operation_id', $id)->count(), 409, 'An operation item disappeared.');
        abort_if((clone $scope)->where('source_kind', '!=', 'managed')->exists(), 409, 'An item source changed.');
        abort_if((clone $scope)->whereNotNull('purging_at')->exists(), 409, 'An item is being permanently removed.');
        abort_if(DB::table('fms_tree_operation_nodes as n')->join('fms_entries as e', 'e.id', '=', 'n.entry_id')->where('n.operation_id', $id)->whereColumn('n.revision', '!=', 'e.revision')->exists(), 409, 'An item changed during preparation.');
        if ($operation->operation === 'trash') {
            abort_if((clone $scope)->whereNotNull('trashed_at')->exists(), 409, 'An item was already trashed.');
            abort_if(DB::table('fms_usages')->whereIn('entry_id', $members)->exists(), 409, 'A folder contains an application-referenced item.');
        } else {
            abort_if((clone $scope)->whereNull('trashed_at')->exists(), 409, 'A trash item changed.');
            abort_if(DB::table('fms_tree_operations')->whereIn('id', (clone $scope)->whereNotNull('trash_group_id')->select('trash_group_id'))->whereNotNull('purging_at')->exists(), 409, 'Permanent cleanup has started.');
            $parents = (clone $scope)->whereNotNull('parent_id')->whereNotIn('parent_id', $members)->select('parent_id');
            abort_if(DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereIn('id', $parents)->whereNotNull('trashed_at')->exists(), 409, 'Restore the parent folder first.');
        }
        $changes = ['trashed_at' => $operation->operation === 'trash' ? now() : null,
            'trash_group_id' => $operation->operation === 'trash' ? $id : null, 'updated_at' => now()];
        if ($operation->operation === 'trash') {
            $changes += ['trashed_by_key' => $principal->key(), 'trashed_by_type' => $principal->type, 'trashed_by_id' => $principal->id];
        }
        try {
            $scope->increment('revision', 1, $changes);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'An item with this name already exists in the destination folder.');
        }
        if ($operation->operation === 'trash') {
            app(TrashShares::class)->remove((int) $workspace->id, $members);
        }
        $roots = DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereIn('public_id', json_decode($operation->roots, true, 8, JSON_THROW_ON_ERROR))->get();
        app(ActivityLog::class)->recordMany($workspace, $principal, $roots, $operation->operation === 'trash' ? 'trashed' : 'restored', $id);
        DB::table('fms_tree_operations')->where('id', $id)->update(['state' => 'completed', 'updated_at' => now()]);
        $this->unlock($workspace, $id);
    }

    private function finishFailure(Principal $principal, string $workspaceId, string $id, string $error): void
    {
        DB::transaction(function () use ($principal, $workspaceId, $id, $error): void {
            [$workspace, $operation] = $this->resolve($principal, $workspaceId, $id, true);
            if ($operation->state !== 'preparing') {
                return;
            }
            DB::table('fms_tree_operations')->where('id', $id)->update(['state' => 'failed', 'error' => mb_substr($error, 0, 512), 'updated_at' => now()]);
            $this->unlock($workspace, $id);
        }, 3);
    }

    private function unlock(object $workspace, string $id): void
    {
        DB::table('fms_workspaces')->where('id', $workspace->id)->where('tree_operation_id', $id)->update(['tree_operation_id' => null]);
        app(NavigationChanges::class)->record((int) $workspace->id, 'invalidate', ['operationId' => $id]);
    }
}
