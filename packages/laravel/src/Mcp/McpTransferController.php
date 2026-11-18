<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Http\FileContent;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Library\UploadAsset;
use UseFlex\MediaStudio\Renditions\ImportedRenditions;
use UseFlex\MediaStudio\Uploads\UploadSessions;

final class McpTransferController
{
    public function __construct(private StudioOperations $operations, private WorkspaceAccess $workspaces) {}

    public function download(Request $request, string $workspace, string $entry, string $version, FileContent $content): Response
    {
        $this->operations->requireTransfer($workspace, 'studio_prepare_file_download');
        $request->attributes->set('fms.required-operation', ['workspace' => $workspace, 'operation' => 'files.download']);

        return $content->send($request, $workspace, $entry, $version, download: true);
    }

    public function store(Request $request, string $workspace, UploadAsset $uploads, Entries $entries): JsonResponse
    {
        return $this->upload($request, $workspace, $uploads, $entries, false);
    }

    public function importImages(Request $request, string $workspace, UploadAsset $uploads, Entries $entries): JsonResponse
    {
        return $this->upload($request, $workspace, $uploads, $entries, true);
    }

    private function upload(Request $request, string $workspace, UploadAsset $uploads, Entries $entries, bool $bundle): JsonResponse
    {
        $tool = $bundle ? 'studio_prepare_image_import' : 'studio_prepare_file_upload';
        $client = $this->operations->requireTransfer($workspace, $tool);
        $request->attributes->set('fms.required-operation', ['workspace' => $workspace, 'operation' => 'uploads.create']);
        $resolved = $this->workspaces->resolve($client->principal, $workspace, write: true);
        $max = (int) config('media-studio.upload.max_kilobytes', 25600);
        $data = $request->validate([
            'parent' => ['nullable', 'ulid'],
            'file' => ['required', 'file', 'max:'.$max],
            ...($bundle ? [
                'variants' => ['required', 'array', 'min:1', 'max:'.ImportedRenditions::MAX_FILES],
                'variants.*' => ['required', 'file', 'max:'.$max],
                'roles' => ['sometimes', 'array:thumbnail,preview'],
                'roles.*' => ['required', 'string', 'max:23'],
            ] : []),
        ]);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/\A[a-zA-Z0-9_-]{16,128}\z/', $key), 422, 'A valid Idempotency-Key is required.');
        $parent = $entries->parentId($resolved->id, $data['parent'] ?? null);
        $imported = $bundle ? ImportedRenditions::prepare($resolved, $data['file'], $data['variants'], $data['roles'] ?? []) : null;
        $resolve = $imported === null ? null : function (bool $lock) use ($client, $workspace, $imported): object {
            $current = $this->workspaces->resolve($client->principal, $workspace, write: true, lock: $lock);
            $imported->checkPolicy($current);

            return $current;
        };
        $entry = $uploads->execute($resolved, $client->principal, $parent, $data['file'], $client->scope().':'.$key,
            beforePublish: fn () => $this->operations->requireTransfer($workspace, $tool), workspaceResolver: $resolve, imported: $imported);
        Log::info('Media Studio MCP uploaded file', ['connection' => $client->id, 'workspace' => $workspace, 'entry' => $entry->public_id]);

        return response()->json(StudioOperations::entry($entry), 201);
    }

    public function part(Request $request, string $workspace, string $session, string $part, UploadSessions $uploads): JsonResponse
    {
        $client = $this->operations->requireTransfer($workspace, 'studio_begin_upload');
        $request->attributes->set('fms.required-operation', ['workspace' => $workspace, 'operation' => 'uploads.create']);
        $hash = $request->header('X-Part-SHA256');
        abort_unless(is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/', $hash), 422, 'A valid X-Part-SHA256 is required.');
        $stream = $request->getContent(true);
        try {
            $uploads->part($client->principal, $workspace, $session, (int) $part, $stream, $hash, $client->scope());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return response()->json(['accepted' => true]);
    }
}
