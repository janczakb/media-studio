<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Assistant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Comments\FileComments;
use UseFlex\MediaStudio\Http\SharingController;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Library\EntryOperations;
use UseFlex\MediaStudio\Library\EntryTags;
use UseFlex\MediaStudio\Library\PersonalLibrary;
use UseFlex\MediaStudio\Storage\Encryption\DatabasePayloads;

final class AssistantActionsController
{
    public function undo(Request $request, string $workspace, string $action)
    {
        abort_unless(app(AssistantConfiguration::class)->available(), 404);
        $principal = Principal::fromUser($request->user());
        $scope = app(WorkspaceAccess::class)->resolve($principal, $workspace);

        return DB::transaction(function () use ($request, $principal, $scope, $workspace, $action) {
            $row = DB::table('fms_ai_actions as a')->join('fms_ai_runs as r', 'r.id', '=', 'a.run_id')->join('fms_ai_conversations as c', 'c.id', '=', 'r.conversation_id')
                ->where('c.workspace_id', $scope->id)->where('c.principal_key', $principal->key())->where('a.id', $action)->select('a.*')->lockForUpdate()->first();
            abort_unless($row && $row->undo !== null, 404);
            $u = json_decode(app(DatabasePayloads::class)->open('fms_ai_actions', $row->id, 'undo', $row->undo), true, flags: JSON_THROW_ON_ERROR);
            $context = new AssistantToolContext($row->run_id, $principal, $workspace, TenantIdentity::fromWorkspace($scope));
            $context->execute($u['operation'], function ($w, $actor) use ($request, $u, $row, $principal, $workspace) {
                if ($row->undone_at !== null) {
                    return;
                }
                if ($u['kind'] === 'share') {
                    $input = Request::create('/', 'PATCH', ['revision' => $u['revision'], 'revoked' => true]);
                    $input->setUserResolver($request->getUserResolver());
                    app(SharingController::class)->update($input, $workspace, $u['share']);
                } else {
                    $e = app(Entries::class)->resolve($w->id, $u['entry']);
                    if ($u['kind'] === 'favorite') {
                        $current = DB::table('fms_personal_entries')->where('workspace_id', $w->id)->where('principal_key', $principal->key())->where('entry_id', $e->id)->value('starred_at');
                        abort_unless($current === $u['after'], 409, 'This favorite changed after the assistant action.');
                        app(PersonalLibrary::class)->setStar($principal, $workspace, $u['entry'], $u['before']);
                    } elseif ($u['kind'] === 'tags') {
                        app(EntryTags::class)->replace($principal, $workspace, $u['entry'], $u['revision'], $u['tags']);
                    } elseif ($u['kind'] === 'comment') {
                        app(FileComments::class)->update($actor, $workspace, $u['entry'], $u['comment'], ['revision' => $u['revision'], ...$u['changes']]);
                    } else {
                        app(EntryOperations::class)->execute($principal, $workspace, $u['entry'], $u['kind'] === 'created_folder' ? 'trash' : ($u['action'] ?? 'update'), ['revision' => $u['revision'], ...($u['changes'] ?? [])]);
                    }
                }
                DB::table('fms_ai_actions')->where('id', $row->id)->update(['undone_at' => now()]);
            }, active: false);

            return response()->json(['ok' => true])->header('Cache-Control', 'private, no-store');
        });
    }
}
