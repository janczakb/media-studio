<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Delivery\DeliveryResource;
use UseFlex\MediaStudio\Sharing\ArchiveDownload;
use UseFlex\MediaStudio\Sharing\PrepareArchive;
use UseFlex\MediaStudio\Sharing\Shares;

final class SharedArchiveController
{
    public function create(Request $request, string $token, Shares $shares)
    {
        abort_unless(config('media-studio.downloads.background_enabled', false), 404);
        [$share, $workspace, $principal] = $shares->context($request, $token);
        abort_unless($share->allow_download && $share->allow_archive, 403);
        $data = $request->validate(['parent' => ['nullable', 'ulid']]);
        $key = 'fms-share-archive:'.$share->public_id.':'.hash('sha256', (string) $request->ip());
        abort_if(RateLimiter::increment($key, 60) > 5, 429);
        if (isset($data['parent'])) {
            $folder = $shares->entry($share, $workspace, $principal, $data['parent']);
            abort_unless($folder->kind === 'folder', 404);
            $roots = [$folder];
        } else {
            $rows = DB::table('fms_share_entries as s')->join('fms_entries as e', 'e.id', '=', 's.entry_id')
                ->where('s.share_id', $share->id)->where('e.workspace_id', $workspace->id)->whereNull('e.trashed_at')
                ->orderBy('s.position')->limit(120)->select('e.*')->get()->all();
            $roots = $shares->accessible($workspace, $principal, $rows);
        }
        abort_if($roots === [], 404);
        $id = (string) Str::ulid();
        DB::transaction(function () use ($share, $workspace, $principal, $roots, $id): void {
            $current = DB::table('fms_shares')->where('id', $share->id)->lockForUpdate()->first();
            abort_unless($current !== null && (int) $current->revision === (int) $share->revision && $current->revoked_at === null && $current->allow_download && $current->allow_archive, 403);
            abort_if(DB::table('fms_archive_jobs')->where('share_id', $share->id)->whereIn('state', ['queued', 'processing'])->where('expires_at', '>', now())->count() >= 3, 429);
            abort_if(DB::table('fms_archive_jobs')->where('share_id', $share->id)->where('expires_at', '>', now())->count() >= 10, 429);
            DB::table('fms_archive_jobs')->insert(['id' => $id, 'workspace_id' => $workspace->id,
                'principal_type' => $principal->type, 'principal_id' => $principal->id, 'principal_key' => $principal->key(),
                'share_id' => $share->id, 'share_revision' => $share->revision,
                'entries' => json_encode(array_column($roots, 'public_id'), JSON_THROW_ON_ERROR), 'state' => 'queued',
                'disk' => config('media-studio.downloads.background_disk', 'local'),
                'expires_at' => now()->addHours(max(1, min(168, (int) config('media-studio.downloads.background_ttl_hours', 24)))),
                'created_at' => now(), 'updated_at' => now()]);
        });
        $job = new PrepareArchive($id);
        $job->onConnection(config('media-studio.downloads.connection'))->onQueue(config('media-studio.downloads.queue'));
        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (\Throwable $error) {
            DB::table('fms_archive_jobs')->where('id', $id)->where('state', 'queued')->update(['state' => 'failed', 'updated_at' => now()]);
            throw $error;
        }
        $url = route('media-studio.shared.archive-status', ['token' => $token, 'job' => $id]);

        return $request->expectsJson() ? response()->json(['id' => $id, 'statusUrl' => $url], 202) : redirect($url);
    }

    public function status(Request $request, string $token, string $job, Shares $shares)
    {
        [$row] = $this->record($request, $token, $job, $shares);
        $download = route('media-studio.shared.archive-result', ['token' => $token, 'job' => $job]);
        if ($request->expectsJson()) {
            return response()->json(['id' => $row->id, 'state' => $row->state, 'downloadUrl' => $row->state === 'ready' ? $download : null]);
        }
        $state = htmlspecialchars($row->state, ENT_QUOTES, 'UTF-8');
        $refresh = in_array($row->state, ['queued', 'processing'], true) ? '<meta http-equiv="refresh" content="5">' : '';
        $link = $row->state === 'ready' ? '<a href="'.htmlspecialchars($download, ENT_QUOTES, 'UTF-8').'">Download archive</a>' : '';

        return response('<!doctype html><html><head><meta charset="utf-8">'.$refresh.'<title>Archive</title></head><body><h1>Archive: '.$state.'</h1>'.$link.'</body></html>')
            ->header('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function result(Request $request, string $token, string $job, Shares $shares, ArchiveDownload $archives, MediaResponse $media)
    {
        [$row, $share, $workspace, $principal] = $this->record($request, $token, $job, $shares);
        abort_unless($row->state === 'ready' && $row->object_key !== null, 409);
        $ids = json_decode($row->entries, true, 512, JSON_THROW_ON_ERROR);
        $roots = DB::table('fms_entries')->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->whereNull('trashed_at')->get()->all();
        $allowed = DB::table('fms_share_entries')->where('share_id', $share->id)->pluck('entry_id')->all();
        abort_unless(count($roots) === count($ids) && count($shares->accessible($workspace, $principal, $roots, $allowed)) === count($roots), 404);
        $limit = max(1, min(50000, (int) config('media-studio.downloads.background_max_entries', 50000)));
        abort_unless(hash_equals($row->fingerprint, $archives->fingerprint($workspace, $principal, $roots, $limit)), 409);

        $record = (object) ['disk' => $row->disk, 'object_key' => $row->object_key, 'size_bytes' => $row->size_bytes, 'mime_type' => 'application/zip', 'name' => 'shared-collection.zip'];

        return $media->stream($request->duplicate(['download' => true]), $record, DeliveryResource::fromRecord($record, $workspace->public_id, $row->id, 'archive', download: true, expiresAt: min(strtotime($row->expires_at), $share->expires_at === null ? PHP_INT_MAX : strtotime($share->expires_at))));
    }

    private function record(Request $request, string $token, string $id, Shares $shares): array
    {
        abort_unless(config('media-studio.downloads.background_enabled', false), 404);
        [$share, $workspace, $principal] = $shares->context($request, $token);
        abort_unless($share->allow_download && $share->allow_archive, 403);
        $row = DB::table('fms_archive_jobs')->where('id', $id)->where('share_id', $share->id)->where('share_revision', $share->revision)->where('expires_at', '>', now())->first();
        abort_if($row === null, 404);

        return [$row, $share, $workspace, $principal];
    }
}
