<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Video\VideoEdits;

final class VideoEditsController
{
    public function show(Request $request, string $workspace, string $entry, WorkspaceAccess $access, Entries $entries, VideoEdits $edits)
    {
        $principal = Principal::fromUser($request->user());
        $w = $access->resolve($principal, $workspace);
        $row = $entries->resolve($w->id, $entry);
        $caps = $edits->capabilities($w, $row, $principal);
        $latest = Schema::hasTable('fms_video_edits') ? DB::table('fms_video_edits')->where('workspace_id', $w->id)->where('entry_id', $row->id)->where('principal_type', $principal->type)->where('principal_id', $principal->id)->orderByDesc('id')->first() : null;

        return response()->json($caps + ['operation' => $latest ? $edits->payload($latest) : null]);
    }

    public function store(Request $request, string $workspace, string $entry, WorkspaceAccess $access, Entries $entries, VideoEdits $edits)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'start' => ['required', 'numeric', 'min:0', 'max:86400'], 'end' => ['required', 'numeric', 'gt:start', 'max:86400'], 'requestId' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]{16,128}\z/']]);
        $principal = Principal::fromUser($request->user());
        $w = $access->resolve($principal, $workspace, write: true);
        $row = $entries->resolve($w->id, $entry);
        $data['revision'] = (int) $data['revision'];
        $data['start'] = (float) $data['start'];
        $data['end'] = (float) $data['end'];

        return response()->json($edits->payload($edits->create($w, $row, $principal, $data, $data['requestId'])), 202);
    }
}
