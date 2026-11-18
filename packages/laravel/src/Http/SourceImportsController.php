<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Sources\DiskIndexer;
use UseFlex\MediaStudio\Sources\DiskMount;
use UseFlex\MediaStudio\Sources\InventoryPage;

final class SourceImportsController
{
    private function context(Request $request, string $workspace): array
    {
        $principal = Principal::fromUser($request->user());
        $resolved = app(WorkspaceAccess::class)->resolve($principal, $workspace, write: true);
        abort_unless(in_array($resolved->role, ['owner', 'admin'], true), 403);

        return [$resolved, $principal];
    }

    private function mount(string $key, object $workspace, Principal $principal): DiskMount
    {
        $mount = DiskMount::find($key);
        abort_unless($mount !== null && $mount->allows($workspace, $principal), 404);

        return $mount;
    }

    public function index(Request $request, string $workspace): JsonResponse
    {
        [$w, $principal] = $this->context($request, $workspace);
        $mounts = [];
        foreach ((array) config('media-studio.sources.mounts', []) as $key => $configuration) {
            $mount = DiskMount::find((string) $key);
            if ($mount !== null && $mount->allows($w, $principal)) {
                $mounts[] = ['key' => $key, 'label' => (string) ($configuration['label'] ?? $key), 'canImport' => is_string($configuration['inventory'] ?? null)];
            }
        }
        $rows = DB::table('fms_source_imports')->where('workspace_id', $w->id)->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get();
        $items = [];
        foreach ($rows as $row) {
            $mount = DiskMount::find($row->mount_key);
            if ($mount !== null && $mount->allows($w, $principal)) {
                $items[] = $this->payload($row);
            }
        }

        return response()->json(['sources' => $mounts, 'items' => $items]);
    }

    public function store(Request $request, string $workspace): JsonResponse
    {
        [$w, $principal] = $this->context($request, $workspace);
        $data = $request->validate(['mount' => 'required|string|max:64']);
        $mount = $this->mount($data['mount'], $w, $principal);
        $page = InventoryPage::read($mount, limit: 0);
        $row = DB::transaction(function () use ($w, $principal, $mount, $page) {
            DB::table('fms_workspaces')->where('id', $w->id)->lockForUpdate()->first();
            $existing = DB::table('fms_source_imports')->where('workspace_id', $w->id)->where('mount_key', $mount->key)->whereIn('state', ['ready', 'running', 'paused'])->first();
            if ($existing !== null) {
                abort_unless($existing->principal_type === $principal->type && $existing->principal_id === $principal->id, 409, 'An import for this source is already owned by another administrator.');

                return $existing;
            }
            abort_if(DB::table('fms_source_imports')->where('workspace_id', $w->id)->count() >= 1000, 422, 'Import history limit reached.');
            $id = (string) Str::ulid();
            DB::table('fms_source_imports')->insert(['id' => $id, 'workspace_id' => $w->id, 'mount_key' => $mount->key, 'principal_type' => $principal->type, 'principal_id' => $principal->id, 'inventory_identity' => $page['identity'], 'mount_revision' => $mount->revision(), 'inventory_bytes' => $page['bytes'], 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('fms_source_imports')->where('id', $id)->first();
        }, 3);

        return response()->json($this->payload($row), 201);
    }

    public function step(Request $request, string $workspace, string $run): JsonResponse
    {
        [$w, $principal] = $this->context($request, $workspace);
        $data = $request->validate(['revision' => 'required|integer|min:0']);
        $token = (string) Str::uuid();
        $row = DB::transaction(function () use ($w, $principal, $run, $data, $token) {
            $row = DB::table('fms_source_imports')->where('workspace_id', $w->id)->where('id', $run)->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_unless($row->principal_type === $principal->type && $row->principal_id === $principal->id, 403, 'Only the initiating administrator can advance this import.');
            $mount = $this->mount($row->mount_key, $w, $principal);
            abort_unless($mount->revision() === $row->mount_revision, 409, 'Source configuration changed.');
            abort_unless((int) $row->revision === $data['revision'] && in_array($row->state, ['ready', 'running'], true) && ($row->lease_until === null || strtotime($row->lease_until) < time()), 409, 'Import changed or another batch is running.');
            DB::table('fms_source_imports')->where('id', $run)->update(['state' => 'running', 'lease_token' => $token, 'lease_until' => now()->addMinutes(5), 'updated_at' => now()]);

            return $row;
        }, 3);
        try {
            $mount = $this->mount($row->mount_key, $w, $principal);
            $page = InventoryPage::read($mount, (int) $row->byte_offset, 60, $row->inventory_identity);
            $publish = function () use ($w, $run, $token, $page) {
                $fence = function () use ($w, $run, $token): void {
                    $current = DB::table('fms_source_imports')->where('workspace_id', $w->id)->where('id', $run)->lockForUpdate()->first();
                    abort_unless($current !== null && $current->state === 'running' && $current->lease_token === $token && strtotime($current->lease_until) > time(), 409, 'This import batch is no longer current.');
                };
                $fence();
                DB::table('fms_source_imports')->where('id', $run)->where('lease_token', $token)->update(['byte_offset' => $page['offset'], 'processed' => DB::raw('processed + '.count($page['paths'])), 'state' => $page['offset'] >= $page['bytes'] ? 'completed' : 'ready', 'revision' => DB::raw('revision + 1'), 'lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);
            };
            if ($page['paths'] !== []) {
                app(DiskIndexer::class)->importPage($principal, $workspace, $row->mount_key, $page['paths'], true, $publish);
            } else {
                DB::transaction($publish, 3);
            }
        } catch (\Throwable $error) {
            DB::table('fms_source_imports')->where('id', $run)->where('lease_token', $token)->update(['state' => 'failed', 'revision' => DB::raw('revision + 1'), 'lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);
            throw $error;
        }

        return response()->json($this->payload(DB::table('fms_source_imports')->where('id', $run)->first()));
    }

    public function update(Request $request, string $workspace, string $run): JsonResponse
    {
        [$w, $principal] = $this->context($request, $workspace);
        $data = $request->validate(['revision' => 'required|integer|min:0', 'action' => 'required|in:pause,resume,cancel']);
        $row = DB::transaction(function () use ($w, $principal, $run, $data) {
            $row = DB::table('fms_source_imports')->where('workspace_id', $w->id)->where('id', $run)->lockForUpdate()->first();
            abort_if($row === null, 404);
            $this->mount($row->mount_key, $w, $principal);
            abort_unless((int) $row->revision === $data['revision'] && ! in_array($row->state, ['completed', 'cancelled'], true), 409);
            abort_unless($data['action'] !== 'resume' || ($row->principal_type === $principal->type && $row->principal_id === $principal->id), 403);
            DB::table('fms_source_imports')->where('id', $run)->update(['state' => match ($data['action']) {
                'pause' => 'paused', 'resume' => 'ready', 'cancel' => 'cancelled'
            }, 'revision' => DB::raw('revision + 1'), 'lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);

            return DB::table('fms_source_imports')->where('id', $run)->first();
        }, 3);

        return response()->json($this->payload($row));
    }

    private function payload(object $row): array
    {
        $principal = Principal::fromUser(request()->user());

        return ['canAdvance' => $row->principal_type === $principal->type && $row->principal_id === $principal->id, 'id' => $row->id, 'mount' => $row->mount_key, 'state' => $row->state, 'revision' => (int) $row->revision, 'processed' => (int) $row->processed, 'progress' => (int) $row->inventory_bytes === 0 ? ($row->state === 'completed' ? 100 : 0) : min(100, (int) floor(100 * $row->byte_offset / $row->inventory_bytes))];
    }
}
