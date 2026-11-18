<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Delivery\DeliveryResource;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Sharing\ArchiveDownload;
use UseFlex\MediaStudio\Sharing\PrepareArchive;
use UseFlex\MediaStudio\Sharing\Shares;

final class ArchiveController
{
    public function index(Request $request, string $workspace, WorkspaceAccess $access): JsonResponse
    {
        abort_unless(config('media-studio.downloads.background_enabled', false), 404);
        $principal = Principal::fromUser($request->user());
        $w = $access->resolve($principal, $workspace);
        $items = DB::table('fms_archive_jobs')->where('workspace_id', $w->id)->where('principal_key', $principal->key())->whereNull('share_id')
            ->where('expires_at', '>', now())->orderByDesc('created_at')->orderByDesc('id')->limit(20)
            ->get(['id', 'state', 'expires_at']);

        return response()->json(['items' => $items])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, string $workspace, WorkspaceAccess $access, Shares $shares): JsonResponse
    {
        abort_unless(config('media-studio.downloads.background_enabled', false), 404);
        $data = $request->validate(['entries' => ['required', 'array', 'list', 'min:1', 'max:100'], 'entries.*' => ['required', 'ulid', 'distinct']]);
        $principal = Principal::fromUser($request->user());
        $w = $access->resolve($principal, $workspace);
        $rows = DB::table('fms_entries')->where('workspace_id', $w->id)->whereIn('public_id', $data['entries'])->whereNull('trashed_at')->get()->all();
        abort_unless(count($rows) === count($data['entries']) && count($shares->accessible($w, $principal, $rows)) === count($rows), 404);
        $id = (string) Str::ulid();
        DB::table('fms_archive_jobs')->insert(['id' => $id, 'workspace_id' => $w->id, 'principal_type' => $principal->type,
            'principal_id' => $principal->id, 'principal_key' => $principal->key(), 'entries' => json_encode($data['entries'], JSON_THROW_ON_ERROR),
            'state' => 'queued', 'disk' => config('media-studio.downloads.background_disk', 'local'),
            'expires_at' => now()->addHours(max(1, min(168, (int) config('media-studio.downloads.background_ttl_hours', 24)))),
            'created_at' => now(), 'updated_at' => now()]);
        $job = new PrepareArchive($id);
        $job->onConnection(config('media-studio.downloads.connection'))->onQueue(config('media-studio.downloads.queue'));
        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (\Throwable $exception) {
            DB::table('fms_archive_jobs')->where('id', $id)->where('state', 'queued')->update(['state' => 'failed', 'updated_at' => now()]);
            throw $exception;
        }

        return response()->json(['id' => $id, 'state' => DB::table('fms_archive_jobs')->where('id', $id)->value('state')], 202)->header('Cache-Control', 'private, no-store');
    }

    public function status(Request $request, string $workspace, string $job, WorkspaceAccess $access): JsonResponse
    {
        [, , $row] = $this->record($request, $workspace, $job, $access);

        return response()->json(['id' => $row->id, 'state' => $row->state, 'expiresAt' => $row->expires_at])->header('Cache-Control', 'private, no-store');
    }

    public function result(Request $request, string $workspace, string $job, WorkspaceAccess $access, Shares $shares, ArchiveDownload $archives, MediaResponse $media): Response
    {
        [$w, $principal, $row] = $this->record($request, $workspace, $job, $access);
        abort_unless($row->state === 'ready' && $row->object_key !== null, 409);
        $ids = json_decode($row->entries, true, 512, JSON_THROW_ON_ERROR);
        $roots = DB::table('fms_entries')->where('workspace_id', $w->id)->whereIn('public_id', $ids)->whereNull('trashed_at')->get()->all();
        abort_unless(count($roots) === count($ids) && count($shares->accessible($w, $principal, $roots)) === count($roots), 404);
        $limit = max(1, min(50000, (int) config('media-studio.downloads.background_max_entries', 50000)));
        abort_unless(hash_equals($row->fingerprint, $archives->fingerprint($w, $principal, $roots, $limit)), 409, 'The collection changed. Prepare a new archive.');

        $record = (object) ['disk' => $row->disk, 'object_key' => $row->object_key, 'size_bytes' => $row->size_bytes, 'mime_type' => 'application/zip', 'name' => 'media.zip'];

        return $media->stream($request->duplicate(['download' => true]), $record, DeliveryResource::fromRecord($record, $w->public_id, $row->id, 'archive', download: true, expiresAt: strtotime($row->expires_at)));
    }

    private function record(Request $request, string $workspace, string $job, WorkspaceAccess $access): array
    {
        abort_unless(config('media-studio.downloads.background_enabled', false), 404);
        $principal = Principal::fromUser($request->user());
        $w = $access->resolve($principal, $workspace);
        $row = DB::table('fms_archive_jobs')->where('id', $job)->where('workspace_id', $w->id)->where('principal_key', $principal->key())->whereNull('share_id')->where('expires_at', '>', now())->first();
        abort_if($row === null, 404);

        return [$w, $principal, $row];
    }

    public function download(Request $request, string $workspace, WorkspaceAccess $access, Shares $shares, ArchiveDownload $archives): StreamedResponse
    {
        $data = $request->validate(['entries' => ['required', 'array', 'list', 'min:1', 'max:100'], 'entries.*' => ['required', 'ulid', 'distinct']]);
        $principal = Principal::fromUser($request->user());
        $w = $access->resolve($principal, $workspace);
        $rows = DB::table('fms_entries')->where('workspace_id', $w->id)->whereIn('public_id', $data['entries'])->whereNull('trashed_at')->get()->all();
        abort_unless(count($rows) === count($data['entries']) && count($shares->accessible($w, $principal, $rows)) === count($rows), 404);

        return $archives->response($w, $principal, $rows, fn () => $access->resolve($principal, $workspace), 'media');
    }
}
