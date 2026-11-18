<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sharing;

use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Identity\Principal;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/** Streams indexed originals; it never scans storage or buffers file contents. */
final class ArchiveDownload
{
    public function __construct(private SourcePolicy $sources, private Shares $shares) {}

    public function response(object $workspace, Principal $principal, array $roots, callable $authorize, string $name): StreamedResponse
    {
        $limit = max(1, min(50000, (int) config('media-studio.downloads.max_archive_entries', 10000)));
        $count = 0;
        foreach ($this->walk($workspace, $principal, $roots) as $_) {
            abort_if(++$count > $limit, 413, 'This archive exceeds the configured item limit.');
        }

        return response()->streamDownload(function () use ($workspace, $principal, $roots, $authorize, $limit): void {
            $this->write($workspace, $principal, $roots, $authorize, null, $limit);
        }, $name.'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Write a ZIP to an output stream and return a fingerprint of its live contents. */
    public function write(object $workspace, Principal $principal, array $roots, callable $authorize, mixed $output, int $limit): string
    {
        $zip = new ZipStream(outputStream: $output, sendHttpHeaders: false, defaultCompressionMethod: CompressionMethod::STORE);
        $hash = hash_init('sha256');
        $count = 0;
        foreach ($this->walk($workspace, $principal, $roots) as [$row, $path]) {
            if ($output === null && connection_aborted()) {
                throw new \RuntimeException('Archive download was interrupted.');
            }
            abort_if(++$count > $limit, 413);
            $authorize();
            $current = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('id', $row->id)->whereNull('trashed_at')->first();
            abort_if($current === null || $this->shares->accessible($workspace, $principal, [$current], array_column($roots, 'id')) === [], 404);
            hash_update($hash, json_encode([$current->id, $current->parent_id, $current->revision, $current->current_version_id, $path], JSON_THROW_ON_ERROR));
            if ($current->kind === 'folder') {
                $zip->addFile($path.'/', '');

                continue;
            }
            $version = DB::table('fms_asset_versions')->where('id', $current->current_version_id)->where('entry_id', $current->id)->first();
            abort_if($version === null, 404);
            $record = (object) [...(array) $version, 'id' => $current->id, 'current_version_id' => $version->id, 'source_kind' => $current->source_kind];
            $this->sources->original($workspace, $principal, $record);
            $stream = Storage::disk($version->disk)->readStream($version->object_key);
            abort_unless(is_resource($stream), 404);
            try {
                $zip->addFileFromStream($path, $stream, exactSize: (int) $version->size_bytes);
            } finally {
                fclose($stream);
            }
        }
        $zip->finish();

        return hash_final($hash);
    }

    public function fingerprint(object $workspace, Principal $principal, array $roots, int $limit): string
    {
        $hash = hash_init('sha256');
        $count = 0;
        foreach ($this->walk($workspace, $principal, $roots) as [$row, $path]) {
            abort_if(++$count > $limit, 413);
            abort_if($this->shares->accessible($workspace, $principal, [$row], array_column($roots, 'id')) === [], 404);
            hash_update($hash, json_encode([$row->id, $row->parent_id, $row->revision, $row->current_version_id, $path], JSON_THROW_ON_ERROR));
            if ($row->kind === 'file') {
                $version = DB::table('fms_asset_versions')->where('id', $row->current_version_id)->where('entry_id', $row->id)->first();
                abort_if($version === null, 404);
                $this->sources->original($workspace, $principal, (object) [...(array) $version, 'id' => $row->id, 'current_version_id' => $version->id, 'source_kind' => $row->source_kind]);
            }
        }

        return hash_final($hash);
    }

    private function walk(object $workspace, Principal $principal, array $roots): Generator
    {
        $names = [];
        foreach ($roots as $root) {
            $name = $this->uniqueName($root, $names);
            yield from $this->walkEntry($workspace, $principal, $root, $name, 0);
        }
    }

    private function walkEntry(object $workspace, Principal $principal, object $row, string $path, int $depth): Generator
    {
        abort_if($depth > 64, 422, 'Folder nesting is too deep.');
        yield [$row, $path];
        if ($row->kind !== 'folder') {
            return;
        }
        $last = 0;
        $names = [];
        do {
            $batch = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('parent_id', $row->id)
                ->whereNull('trashed_at')->where('id', '>', $last)->orderBy('id')->limit(100)->get();
            foreach ($this->sources->visible($workspace, $principal, $batch->all()) as $child) {
                yield from $this->walkEntry($workspace, $principal, $child, $path.'/'.$this->uniqueName($child, $names), $depth + 1);
            }
            if ($batch->isNotEmpty()) {
                $last = $batch->last()->id;
            }
        } while ($batch->count() === 100);
    }

    private function uniqueName(object $row, array &$names): string
    {
        $name = $this->safeName($row->name);
        $key = mb_strtolower($name);
        if (isset($names[$key])) {
            $name .= '-'.$row->public_id;
            $key = mb_strtolower($name);
        }
        $names[$key] = true;

        return $name;
    }

    private function safeName(string $name): string
    {
        $name = trim((string) preg_replace('~[\\\\/\x00-\x1f\x7f]+~u', '_', $name), ' .');

        return $name === '' ? 'unnamed' : $name;
    }
}
