<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use UseFlex\MediaCore\Encryption\EncryptionFailure;

/** At most one ciphertext segment per read; S3 uses native ranged GETs. */
final class CiphertextSource
{
    private mixed $stream = null;

    public readonly int $size;

    public function __construct(private readonly FilesystemAdapter $disk, private readonly string $path)
    {
        $this->size = $disk->size($path);
        if (! $disk instanceof AwsS3V3Adapter) {
            $this->stream = $disk->readStream($path);
            if (! is_resource($this->stream) || ! (stream_get_meta_data($this->stream)['seekable'] ?? false)) {
                throw new EncryptionFailure('Encrypted storage requires seekable local streams or native S3 ranges.');
            }
        }
    }

    public function read(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 1 || $length > 1048576 || $offset > $this->size - $length) {
            throw new EncryptionFailure('Invalid ciphertext range.');
        }
        if ($this->disk instanceof AwsS3V3Adapter) {
            $result = $this->disk->getClient()->getObject([
                'Bucket' => $this->disk->getConfig()['bucket'], 'Key' => $this->disk->path($this->path),
                'Range' => 'bytes='.$offset.'-'.($offset + $length - 1),
                '@http' => ['stream' => true, 'connect_timeout' => 5, 'timeout' => 30], '@retries' => 1,
            ]);
            if ((int) $result['ContentLength'] !== $length || ($result['ContentRange'] ?? '') !== 'bytes '.$offset.'-'.($offset + $length - 1).'/'.$this->size) {
                $result['Body']->close();
                throw new EncryptionFailure('Storage did not honor the ciphertext range.');
            }
            try {
                $bytes = '';
                while (strlen($bytes) < $length) {
                    $chunk = $result['Body']->read($length - strlen($bytes));
                    if ($chunk === '') {
                        throw new EncryptionFailure('Ciphertext range was interrupted.');
                    }
                    $bytes .= $chunk;
                }
            } finally {
                $result['Body']->close();
            }
        } else {
            if (fseek($this->stream, $offset) !== 0) {
                throw new EncryptionFailure('Ciphertext seek failed.');
            }
            $bytes = '';
            while (strlen($bytes) < $length) {
                $chunk = fread($this->stream, $length - strlen($bytes));
                if ($chunk === false || $chunk === '') {
                    throw new EncryptionFailure('Ciphertext read failed.');
                }
                $bytes .= $chunk;
            }
        }
        if (strlen($bytes) !== $length) {
            throw new EncryptionFailure('Ciphertext range is incomplete.');
        }

        return $bytes;
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
