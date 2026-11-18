<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystem;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InvalidVisibilityProvided;
use UseFlex\MediaCore\Encryption\AesGcmHkdfStream;
use UseFlex\MediaCore\Encryption\EncryptedRangeReader;
use UseFlex\MediaCore\Encryption\EncryptionFailure;

/** Experimental opt-in disk. See the security release gates in ARCHITECTURE.md. */
final class EncryptedFilesystemAdapter implements FilesystemAdapter
{
    private const MAGIC = 'FMSENC01';

    private const PREFIX_BYTES = 41;

    private const SEGMENTS = [4096, 65536, 1048576];

    public function __construct(private readonly LaravelFilesystem $backing, private readonly ContentKeyring $keys, private readonly string $scope, private readonly int $segmentBytes = 65536)
    {
        if ($scope === '' || strlen($scope) > 256 || ! in_array($segmentBytes, self::SEGMENTS, true)) {
            throw new EncryptionFailure('Invalid encrypted disk configuration.');
        }
    }

    public function fileExists(string $path): bool
    {
        return $this->backing->getDriver()->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->backing->getDriver()->directoryExists($path);
    }

    public function delete(string $path): void
    {
        $this->backing->getDriver()->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->backing->getDriver()->deleteDirectory($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $input = fopen('php://memory', 'w+b');
        try {
            fwrite($input, $contents);
            rewind($input);
            $this->writeStream($path, $input, $config);
        } finally {
            fclose($input);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->assertPrivate($config->get('visibility', 'private'));
        $temporary = tmpfile(); // Only ciphertext is persisted in this staging stream.
        if ($temporary === false) {
            throw new EncryptionFailure('Encrypted staging stream could not be created.');
        }
        try {
            $prefix = self::MAGIC.str_pad($this->keys->active, 32, "\0").chr(array_search($this->segmentBytes, self::SEGMENTS, true));
            if (fwrite($temporary, $prefix) !== self::PREFIX_BYTES) {
                throw new EncryptionFailure('Encrypted object header could not be written.');
            }
            (new AesGcmHkdfStream($this->keys->get($this->keys->active), $this->segmentBytes))->encrypt($contents, $temporary, $this->associatedData($path, $prefix));
            rewind($temporary);
            $heartbeat = $config->get('fms_encryption_heartbeat');
            if (is_callable($heartbeat)) {
                MigrationIntegrityFilter::attach($temporary, (object) ['heartbeat' => $heartbeat]);
            }
            $this->backing->getDriver()->writeStream($path, $temporary, ['visibility' => 'private']);
        } finally {
            fclose($temporary);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        try {
            $contents = stream_get_contents($stream);
            if ($contents === false) {
                throw new EncryptionFailure('Encrypted stream could not be read.');
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    public function readStream(string $path)
    {
        return EncryptedReadStream::open($this->reader($path));
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->assertPrivate($config->get('visibility', 'private'));
        $this->backing->getDriver()->createDirectory($path, ['visibility' => 'private']);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->assertPrivate($visibility);
        $this->backing->getDriver()->setVisibility($path, 'private');
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, visibility: $this->backing->getDriver()->visibility($path));
    }

    public function mimeType(string $path): FileAttributes
    {
        $reader = $this->reader($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($reader->read(0, min(4096, $reader->size)));

        return new FileAttributes($path, mimeType: $mime ?: 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        return new FileAttributes($path, lastModified: $this->backing->getDriver()->lastModified($path));
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, fileSize: $this->reader($path)->size);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        foreach ($this->backing->getDriver()->listContents($path, $deep) as $item) {
            // Never report ciphertext size as plaintext size or perform N+1 decryption.
            yield $item->isFile() ? new FileAttributes($item->path(), visibility: $item->visibility(), lastModified: $item->lastModified()) : $item;
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        if ($source === $destination) {
            return;
        }
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        if ($source === $destination) {
            return;
        }
        $stream = $this->readStream($source);
        try {
            // New salt/nonce and destination AAD, also upgrading to the active key.
            $this->writeStream($destination, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    private function reader(string $path): EncryptedRangeReader
    {
        $source = new CiphertextSource($this->backing, $path);
        $prefix = $source->read(0, self::PREFIX_BYTES);
        $id = rtrim(substr($prefix, 8, 32), "\0");
        $segmentBytes = self::SEGMENTS[ord($prefix[40])] ?? null;
        if (substr($prefix, 0, 8) !== self::MAGIC || ! preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/', $id) || $segmentBytes === null || substr($prefix, 8, 32) !== str_pad($id, 32, "\0")) {
            throw new EncryptionFailure('Encrypted object header is invalid.');
        }
        $reader = (new AesGcmHkdfStream($this->keys->get($id), $segmentBytes))->reader(
            fn (int $offset, int $length): string => $source->read($offset + self::PREFIX_BYTES, $length),
            $source->size - self::PREFIX_BYTES, $this->associatedData($path, $prefix),
        );
        $reader->verifyEnd();

        return $reader;
    }

    private function associatedData(string $path, string $prefix): string
    {
        return json_encode(['fms-storage-v1', $this->scope, $path, bin2hex($prefix)], JSON_THROW_ON_ERROR);
    }

    private function assertPrivate(string $visibility): void
    {
        if ($visibility !== 'private') {
            throw new InvalidVisibilityProvided('Encrypted objects must remain private.');
        }
    }
}
