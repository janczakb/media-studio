<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Navigation;

use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Library\EntryOperations;

/** Idempotent receipt and revision-checked compensation, never a database snapshot restore. */
final class NavigationOperations
{
    public function __construct(private WorkspaceAccess $access, private Entries $entries, private EntryOperations $operations) {}

    public function run(Principal $principal, string $workspaceId, string $requestKey, array $command, array $ids, Closure $write): array
    {
        return DB::transaction(function () use ($principal, $workspaceId, $requestKey, $command, $ids, $write): array {
            $workspace = $this->access->resolve($principal, $workspaceId, write: true, lock: true);
            $principalHash = hash('sha256', $principal->key());
            $fingerprint = hash('sha256', json_encode($command, JSON_THROW_ON_ERROR));
            $receipt = DB::table('fms_navigation_operations')->where('workspace_id', $workspace->id)->where('principal_hash', $principalHash)->where('request_key', $requestKey)->first();
            if ($receipt !== null) {
                abort_unless(hash_equals($receipt->fingerprint, $fingerprint), 409, 'This request key was already used for another operation.');
                abort_if(strtotime($receipt->expires_at) <= time(), 409, 'This operation receipt expired. Refresh before retrying.');

                return [...json_decode(Crypt::decryptString($receipt->result_payload), true, 32, JSON_THROW_ON_ERROR), 'operationId' => $receipt->public_id, 'undoAvailable' => $receipt->undone_at === null];
            }
            $rows = DB::table('fms_entries as n')->leftJoin('fms_entries as p', function ($join): void {
                $join->on('p.id', '=', 'n.parent_id')->on('p.workspace_id', '=', 'n.workspace_id');
            })->where('n.workspace_id', $workspace->id)->whereIn('n.public_id', $ids)->get(['n.public_id', 'n.name', 'p.public_id as previous_parent']);
            abort_unless($rows->count() === count($ids), 404);
            $before = $rows->map(fn ($row) => ['id' => $row->public_id, 'name' => $row->name, 'parent' => $row->previous_parent])->all();
            $result = $write();
            $id = (string) Str::ulid();
            DB::table('fms_navigation_operations')->insert([
                'public_id' => $id, 'workspace_id' => $workspace->id, 'principal_hash' => $principalHash,
                'request_key' => $requestKey, 'fingerprint' => $fingerprint,
                'before_payload' => Crypt::encryptString(json_encode($before, JSON_THROW_ON_ERROR)),
                'result_payload' => Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)),
                'created_at' => now(), 'expires_at' => now()->addDay(),
            ]);

            return [...$result, 'operationId' => $id, 'undoAvailable' => true];
        }, 3);
    }

    public function undo(Principal $principal, string $workspaceId, string $operation): array
    {
        return DB::transaction(function () use ($principal, $workspaceId, $operation): array {
            $workspace = $this->access->resolve($principal, $workspaceId, write: true, lock: true);
            $receipt = DB::table('fms_navigation_operations')->where('workspace_id', $workspace->id)->where('principal_hash', hash('sha256', $principal->key()))->where('public_id', $operation)->first();
            abort_if($receipt === null, 404);
            abort_if(strtotime($receipt->expires_at) <= time(), 409, 'This operation can no longer be undone.');
            if ($receipt->undone_at !== null) {
                return ['undone' => true];
            }
            $result = json_decode(Crypt::decryptString($receipt->result_payload), true, 32, JSON_THROW_ON_ERROR);
            $revisions = array_column($result['items'], 'revision', 'id');
            $before = json_decode(Crypt::decryptString($receipt->before_payload), true, 16, JSON_THROW_ON_ERROR);
            $items = [];
            foreach ($before as $old) {
                $row = $this->operations->execute($principal, $workspaceId, $old['id'], 'update', ['revision' => $revisions[$old['id']], 'name' => $old['name'], 'parent' => $old['parent']]);
                $row->workspace_public_id = $workspaceId;
                $items[] = $this->entries->card($row);
            }
            DB::table('fms_navigation_operations')->where('id', $receipt->id)->update(['undone_at' => now()]);

            return ['undone' => true, 'items' => $items];
        }, 3);
    }
}
