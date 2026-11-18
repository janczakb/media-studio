<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use UseFlex\MediaCore\Encryption\EncryptionFailure;

/** Incremental digest and lease heartbeat, without a plaintext staging file. */
final class MigrationIntegrityFilter extends \php_user_filter
{
    private static bool $registered = false;

    public static function attach(mixed $stream, object $state): mixed
    {
        if (! self::$registered) {
            if (in_array('fms.encryption.integrity', stream_get_filters(), true) || ! stream_filter_register('fms.encryption.integrity', self::class)) {
                throw new EncryptionFailure('Migration integrity filter is unavailable.');
            }
            self::$registered = true;
        }
        $filter = stream_filter_append($stream, 'fms.encryption.integrity', STREAM_FILTER_READ, $state);
        if ($filter === false) {
            throw new EncryptionFailure('Could not attach migration integrity filter.');
        }

        return $filter;
    }

    public function filter($input, $output, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($input)) {
            if (isset($this->params->hash)) {
                hash_update($this->params->hash, $bucket->data);
                $this->params->bytes += $bucket->datalen;
                if ($this->params->bytes > $this->params->expected) {
                    throw new EncryptionFailure('Migration source exceeded its expected size.');
                }
            }
            ($this->params->heartbeat)();
            $consumed += $bucket->datalen;
            stream_bucket_append($output, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
