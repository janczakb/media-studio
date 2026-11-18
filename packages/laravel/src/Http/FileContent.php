<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Delivery\DeliveryResource;
use UseFlex\MediaStudio\Identity\Principal;

final class FileContent
{
    public function __construct(private WorkspaceAccess $access, private SourcePolicy $sources) {}

    public function send(Request $request, string $workspace, string $entry, ?string $version, bool $download): Response
    {
        $record = $this->record(Principal::fromUser($request->user()), $workspace, $entry, $version);

        return app(MediaResponse::class)->stream($request, $record, DeliveryResource::fromRecord($record, $workspace, $record->current_version_id, str_starts_with($record->mime_type, 'video/') && ! $download ? 'video' : 'original', download: $download));
    }

    public function record(Principal $principal, string $workspace, string $entry, ?string $version): object
    {
        $w = $this->access->resolve($principal, $workspace);
        $record = DB::table('fms_entries as e')->join('fms_asset_versions as v', 'v.entry_id', '=', 'e.id')
            ->when($version === null, fn ($q) => $q->whereColumn('v.id', 'e.current_version_id'), fn ($q) => $q->where('v.id', $version))
            ->where(fn ($q) => $q->whereNotIn('e.source_kind', ['spatie', 'disk'])->orWhereColumn('v.id', 'e.current_version_id'))
            ->where('e.workspace_id', $w->id)->where('e.public_id', $entry)->where('e.kind', 'file')->whereNull('e.trashed_at')
            ->select('e.id', 'e.source_kind', 'e.name', 'v.id as current_version_id', 'v.disk', 'v.object_key', 'v.mime_type', 'v.size_bytes')->first();
        abort_if($record === null, 404);

        $this->sources->original($w, $principal, $record);

        return $record;
    }
}
