<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Bounded catalogue diagnostics; never scans complete disks or claims an external audit. */
final class EncryptionReadiness
{
    public function report(): array
    {
        $mode = app(EncryptionProfiles::class)->mode();
        $database = app(DatabasePayloads::class)->enabled();
        $recovery = Schema::hasTable('fms_encryption_copies') && DB::table('fms_encryption_copies')->whereNull('source_deleted_at')->limit(1)->exists();
        $pending = Schema::hasTable('fms_encryption_operations') && DB::table('fms_encryption_operations')->where('state', '!=', 'completed')->limit(1)->exists();

        return [
            'mode' => $mode,
            'fullProtectionVerified' => false,
            'databasePayloadEncryption' => $database,
            'plaintextMigrationReads' => $database && config('media-studio.encryption.database.allow_plaintext_reads') === true,
            'experimentalFileDriverEnabled' => config('media-studio.encryption.experimental_file_driver') === true,
            'migrationInProgressOrFailed' => $pending,
            'recoverySourcesRetained' => $recovery,
            'unverifiedBoundaries' => [
                'Independent cryptographic implementation review',
                'External database backups, logs, cache, queues and search indexes',
                'Host upload buffering, temporary files, swap and crash dumps',
                'Indexed filenames, relational metadata and approximate sizes are not hidden by payload encryption',
                'Existing AI attachment files require expiry/re-upload before enabling encrypted attachments',
                'Retained recovery originals require separately verified retirement',
            ],
        ];
    }
}
