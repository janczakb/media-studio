<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Http\ApiUrl;
use UseFlex\MediaStudio\Storage\Encryption\DatabasePayloads;

final class Entries
{
    public function parentId(int $workspaceId, ?string $publicId, bool $write = false): ?int
    {
        if ($publicId === null) {
            return null;
        }

        $row = DB::table('fms_entries')->where('workspace_id', $workspaceId)->where('public_id', $publicId)->where('kind', 'folder')->whereNull('trashed_at')->first(['id', 'source_kind']);
        abort_if($row === null, 404);
        if ($write) {
            app(SourcePolicy::class)->managed($row);
        }

        return (int) $row->id;
    }

    public function resolve(int $workspaceId, string $publicId): object
    {
        $row = DB::table('fms_entries')->where('workspace_id', $workspaceId)->where('public_id', $publicId)->first();
        abort_if($row === null, 404);

        return $row;
    }

    /** @return array<string, mixed> */
    public function card(object $entry): array
    {
        $document = ! in_array($entry->media_type, ['image', 'video'], true);
        $thumbnailState = $entry->thumbnail_state ?? 'none';
        if ($document && (! config('media-studio.processing.document_thumbnails.serve_existing', true) || (! config('media-studio.processing.document_thumbnails.enabled', false) && $thumbnailState !== 'ready'))) {
            $thumbnailState = 'none';
        }

        return [
            'id' => $entry->public_id,
            'kind' => $entry->kind,
            ...(isset($entry->has_child_folders) ? ['hasChildFolders' => (bool) $entry->has_child_folders] : []),
            'readOnly' => ($entry->source_kind ?? 'managed') !== 'managed',
            'name' => $entry->name,
            ...($entry->media_type === 'note' ? ['noteExcerpt' => \app(DatabasePayloads::class)->open('fms_entries', $entry->id, 'note_excerpt', $entry->note_excerpt ?? null) ?? '', 'noteBackground' => $entry->note_background ?? '#ffffff'] : []),
            'mediaType' => $entry->media_type,
            ...($entry->media_type === 'audio' ? ['audioWaveform' => isset($entry->audio_waveform_version) && $entry->audio_waveform_version === $entry->current_version_id ? $entry->audio_waveform : null] : []),
            'mimeType' => $entry->mime_type,
            'size' => $entry->size_bytes === null ? null : (int) $entry->size_bytes,
            'versionId' => $entry->current_version_id,
            'updatedAt' => $entry->updated_at,
            'createdAt' => $entry->photo_created ?? $entry->created_at ?? $entry->updated_at,
            'revision' => (int) $entry->revision,
            'trashedAt' => $entry->trashed_at,
            'width' => isset($entry->width) ? (int) $entry->width : null,
            'height' => isset($entry->height) ? (int) $entry->height : null,
            'previewState' => $entry->preview_state ?? 'none',
            'previewUrl' => ($entry->preview_state ?? null) === 'ready' && $entry->preview_version_id === $entry->current_version_id
                ? ApiUrl::to('workspaces/'.$entry->workspace_public_id.'/entries/'.$entry->public_id.'/preview?v='.$entry->current_version_id.'&r='.substr(hash('sha256', (string) ($entry->preview_key ?? '')), 0, 16)) : null,
            'thumbnailState' => $thumbnailState,
            'thumbnailUrl' => (($entry->media_type === 'image' || $entry->media_type === 'video') || config('media-studio.processing.document_thumbnails.serve_existing', true)) && ($entry->thumbnail_state ?? null) === 'ready' && $entry->thumbnail_version_id === $entry->current_version_id
                ? ApiUrl::to('workspaces/'.$entry->workspace_public_id.'/entries/'.$entry->public_id.'/thumbnail?v='.$entry->current_version_id.'&r='.substr(hash('sha256', (string) ($entry->thumbnail_key ?? '')), 0, 16)) : null,
        ];
    }

    public function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name), 'UTF-8');
    }
}
