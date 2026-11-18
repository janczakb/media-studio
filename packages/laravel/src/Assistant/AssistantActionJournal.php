<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Assistant;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Storage\Encryption\DatabasePayloads;

final class AssistantActionJournal
{
    /** A domain write and its inverse/receipt commit together. No network I/O in this transaction. */
    public function perform(string $run, string $operation, array $input, Closure $write): array
    {
        return DB::transaction(function () use ($run, $operation, $input, $write): array {
            $r = DB::table('fms_ai_runs')->where('id', $run)->lockForUpdate()->first();
            abort_unless($r && $r->state === 'running', 409, 'The response has stopped.');
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $prior = DB::table('fms_ai_actions')->where('run_id', $run)->where('operation', $operation)->where('input_hash', $hash)->first();
            if ($prior) {
                return json_decode(app(DatabasePayloads::class)->open('fms_ai_actions', $prior->id, 'result', $prior->result), true, flags: JSON_THROW_ON_ERROR);
            }
            abort_if(DB::table('fms_ai_actions')->where('run_id', $run)->count() >= 12, 422, 'At most twelve application actions are allowed per response.');
            $id = (string) Str::ulid();
            $value = $write($id);
            $undo = $value['undo'] ?? null;
            unset($value['undo']);
            $result = ['ok' => true, 'action' => $operation, 'actionId' => $id, 'undoable' => $undo !== null, ...$value];
            DB::table('fms_ai_actions')->insert(app(DatabasePayloads::class)->protect('fms_ai_actions', $id, ['id' => $id, 'run_id' => $run, 'operation' => $operation, 'input_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'undo' => $undo ? json_encode($undo, JSON_THROW_ON_ERROR) : null, 'created_at' => now()]));
            RunAssistant::event($run, 'action', $result);

            return $result;
        });
    }
}
