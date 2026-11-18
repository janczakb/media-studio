<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\UploadAsset;
use UseFlex\MediaStudio\Sharing\Shares;

final class ShareCopyController
{
    public function __invoke(Request $request, string $token, string $entry, Shares $shares, WorkspaceAccess $access, UploadAsset $uploads): RedirectResponse
    {
        abort_unless($request->user(), 403);
        $input = $request->validate(['workspace' => ['required', 'ulid']]);
        $recipient = Principal::fromUser($request->user());
        $destination = function (bool $lock = false) use ($request, $input, $recipient, $access): object {
            $workspace = $access->resolve($recipient, $input['workspace'], write: true, lock: $lock);
            app(StudioPermissions::class)->authorize($request->user(), 'uploads.create', $workspace);

            return $workspace;
        };
        $target = $destination();
        [$share, $source, $owner] = $shares->context($request, $token);
        abort_unless($share->allow_copy && $share->allow_download && $share->visibility === 'publish', 403);
        $row = $shares->entry($share, $source, $owner, $entry);
        abort_unless($row->kind === 'file' && $row->source_kind === 'managed' && $row->current_version_id, 404);
        $version = DB::table('fms_asset_versions')->where('id', $row->current_version_id)->where('entry_id', $row->id)->first();
        abort_unless($version, 404);
        $maximum = max(1, (int) config('media-studio.upload.max_kilobytes', 25600)) * 1024;
        abort_if($version->size_bytes > $maximum, 422, 'This file exceeds the copy size limit.');
        app(SourcePolicy::class)->original($source, $owner, $row);
        $temp = tmpfile();
        abort_unless(is_resource($temp), 503);
        try {
            $stream = Storage::disk($version->disk)->readStream($version->object_key);
            abort_unless(is_resource($stream), 404);
            try {
                $bytes = stream_copy_to_stream($stream, $temp, $maximum + 1);
            } finally {
                fclose($stream);
            }
            abort_unless($bytes === (int) $version->size_bytes && $bytes <= $maximum, 409, 'The source changed during copying.');
            $uploads->execute($target, $recipient, null, new UploadedFile(stream_get_meta_data($temp)['uri'], $row->name, null, null, true),
                hash('sha256', 'share-copy:'.$share->id.':'.$row->id.':'.$version->id),
                beforePublish: function () use ($request, $token, $entry, $shares, $share, $version): void {
                    $fresh = DB::table('fms_shares')->where('id', $share->id)->lockForUpdate()->first();
                    abort_unless($fresh && (int) $fresh->revision === (int) $share->revision, 409);
                    [$current, $workspace, $principal] = $shares->context($request, $token);
                    abort_unless($current->allow_copy && $current->allow_download, 403);
                    $row = $shares->entry($current, $workspace, $principal, $entry);
                    abort_unless($row->current_version_id === $version->id, 409);
                }, workspaceResolver: $destination);
        } finally {
            fclose($temp);
        }

        return redirect($shares->url($token))->with('media-studio-share-success', 'The file was copied to your workspace.');
    }
}
