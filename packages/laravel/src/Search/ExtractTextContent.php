<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaCore\Text\TextExtractor;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Library\Entries;

final class ExtractTextContent
{
    public function run(Principal $actor, string $workspaceId, string $entryId, ?TenantIdentity $tenant): string
    {
        abort_unless(config('media-studio.search.content.enabled') === true, 404);
        $workspace = app(WorkspaceAccess::class)->resolveForWorker($actor, $workspaceId, $tenant, operation: 'files.process');
        $entry = app(Entries::class)->resolve($workspace->id, $entryId);
        $lock = Cache::store(config('media-studio.search.cache_store'))->lock('fms:content:'.hash('sha256', $workspace->id.':'.$entry->id), 420);
        if (! $lock->get()) {
            return 'busy';
        }
        try {
            return $this->extract($actor, $workspaceId, $entryId, $tenant);
        } finally {
            $lock->release();
        }
    }

    private function extract(Principal $actor, string $workspaceId, string $entryId, ?TenantIdentity $tenant): string
    {
        abort_unless(config('media-studio.search.content.enabled') === true, 404);
        $access = app(WorkspaceAccess::class);
        $workspace = $access->resolveForWorker($actor, $workspaceId, $tenant, operation: 'files.process');
        $entry = app(Entries::class)->resolve($workspace->id, $entryId);
        // External sources need their own explicit processing contract; never infer write authority.
        abort_unless($entry->source_kind === 'managed' && $entry->trashed_at === null, 403);
        $version = DB::table('fms_asset_versions')->where('entry_id', $entry->id)->where('id', $entry->current_version_id)->first();
        abort_if($version === null, 404);
        $settings = config('media-studio.search.content', []);
        // Queue routing/admission changes must not spend OCR budget again.
        $processing = array_intersect_key($settings, array_flip(['max_source_bytes', 'max_characters', 'oversize_policy', 'large_threshold_bytes', 'pdf', 'ocr']));
        $profile = hash('sha256', json_encode([$processing, 'text-ocr-v3'], JSON_THROW_ON_ERROR));
        $previous = DB::table('fms_search_content')->where('entry_id', $entry->id)->where('version_id', $version->id)->where('source_profile', $profile)->first();
        if ($previous !== null) {
            return $previous->state;
        }
        $bytes = (int) config('media-studio.search.content.max_source_bytes', 1048576);
        $characters = (int) config('media-studio.search.content.max_characters', 12000);
        if ($bytes < 1 || $bytes > 4194304 || $characters < 1 || $characters > 12000) {
            throw new \LogicException('Invalid bounded text extraction limits.');
        }
        $pdf = $version->mime_type === 'application/pdf' && config('media-studio.search.content.pdf.enabled') === true;
        $mime = app(TextExtractor::class)->mime($version->mime_type, $entry->name);
        $imageOcr = config('media-studio.search.content.ocr.enabled') === true && config('media-studio.search.content.ocr.images') === true && in_array($version->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true);
        if (! $pdf && ! $imageOcr && $mime === null) {
            return 'unsupported';
        }
        $policy = config('media-studio.search.content.oversize_policy', 'skip');
        $threshold = (int) config('media-studio.search.content.large_threshold_bytes', $bytes);
        if (! in_array($policy, ['skip', 'bounded'], true) || $threshold < 1 || $threshold > $bytes) {
            throw new \LogicException('Invalid large-file extraction policy.');
        }
        // A bounded profile may analyze more than the soft threshold, never more
        // than the hard source cap. No remote stream is opened for skipped files.
        if ($version->size_bytes > $bytes || ($policy === 'skip' && $version->size_bytes > $threshold)) {
            return DB::transaction(function () use ($actor, $workspaceId, $tenant, $entryId, $version, $profile): string {
                $workspace = app(WorkspaceAccess::class)->resolveForWorker($actor, $workspaceId, $tenant, lock: true, operation: 'files.process');
                $entry = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('public_id', $entryId)->lockForUpdate()->first();
                abort_unless($entry !== null && $entry->source_kind === 'managed' && $entry->trashed_at === null && $entry->current_version_id === $version->id, 409);
                DB::table('fms_search_content')->upsert([['entry_id' => $entry->id, 'version_id' => $version->id, 'source_profile' => $profile, 'fingerprint' => hash('sha256', 'skipped-size:'.$version->id), 'state' => 'skipped_size', 'content' => '', 'updated_at' => now()]], ['entry_id'], ['version_id', 'source_profile', 'fingerprint', 'state', 'content', 'updated_at']);

                return 'skipped_size';
            });
        }
        $stream = Storage::disk($version->disk)->readStream($version->object_key);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Text source unavailable.');
        }
        try {
            $input = stream_get_contents($stream, $bytes + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($input) || strlen($input) > $bytes || ! hash_equals($version->sha256, hash('sha256', $input))) {
            throw new \RuntimeException('Text source changed or exceeds limits.');
        }
        $beforeOcr = function () use ($access, $actor, $workspaceId, $tenant, $entry, $version): void {
            $access->resolveForWorker($actor, $workspaceId, $tenant, operation: 'files.process');
            abort_unless(DB::table('fms_entries')->where('id', $entry->id)->where('current_version_id', $version->id)->whereNull('trashed_at')->exists(), 409);
        };
        if ($pdf) {
            $result = app(PdfTextExtractor::class)->extract($input, $characters, $beforeOcr);
        } else {
            if ($imageOcr) {
                $beforeOcr();
                $input = app(OcrImage::class)->recognize($input);
            }
            $result = app(TextExtractor::class)->extract($input, $imageOcr ? 'text/plain' : $mime, $characters);
        }
        $state = $result['state'] ?? ($imageOcr && $result['text'] === '' ? 'no_text' : ($result['partial'] ? 'partial' : 'ready'));
        $fingerprint = hash('sha256', json_encode(['text-v1', $version->id, $result], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $workspaceId, $tenant, $entryId, $version, $fingerprint, $result, $state, $profile): string {
            $workspace = app(WorkspaceAccess::class)->resolveForWorker($actor, $workspaceId, $tenant, lock: true, operation: 'files.process');
            $entry = DB::table('fms_entries')->where('workspace_id', $workspace->id)->where('public_id', $entryId)->lockForUpdate()->first();
            abort_unless($entry !== null && $entry->source_kind === 'managed' && $entry->trashed_at === null && $entry->current_version_id === $version->id, 409);
            DB::table('fms_search_content')->upsert([['entry_id' => $entry->id, 'version_id' => $version->id, 'source_profile' => $profile, 'fingerprint' => $fingerprint, 'state' => $state, 'content' => $result['text'], 'updated_at' => now()]], ['entry_id'], ['version_id', 'source_profile', 'fingerprint', 'state', 'content', 'updated_at']);

            return $state;
        });
    }
}
