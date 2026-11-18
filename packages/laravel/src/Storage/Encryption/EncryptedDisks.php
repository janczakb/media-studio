<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem as Flysystem;
use UseFlex\MediaCore\Encryption\EncryptionFailure;

final class EncryptedDisks
{
    public static function make(object $app, array $config): FilesystemAdapter
    {
        if (! in_array(app(EncryptionProfiles::class)->mode(), ['application', 'hybrid'], true) || config('media-studio.encryption.experimental_file_driver') !== true) {
            throw new EncryptionFailure('The experimental encrypted file driver is not enabled.');
        }
        $name = $config['backing_disk'] ?? '';
        if (! is_string($name) || $name === '') {
            throw new EncryptionFailure('Select a named backing disk.');
        }
        $backingConfig = config('filesystems.disks.'.$name);
        if (! is_array($backingConfig) || ! in_array($backingConfig['driver'] ?? '', ['local', 's3', 'fms-s3-native'], true)) {
            throw new EncryptionFailure('Select a dedicated local or S3 backing disk.');
        }
        if (($backingConfig['visibility'] ?? 'private') !== 'private' || ($config['visibility'] ?? 'private') !== 'private') {
            throw new EncryptionFailure('Encrypted disks require private backing storage.');
        }
        $keys = new ContentKeyring((array) config('media-studio.encryption.keys', []), (string) config('media-studio.encryption.active_key', ''));
        $adapter = new EncryptedFilesystemAdapter($app['filesystem']->disk($name), $keys, (string) ($config['scope'] ?? ''), (int) ($config['segment_bytes'] ?? 65536));

        return new FilesystemAdapter(new Flysystem($adapter), $adapter, array_replace($config, ['throw' => true, 'visibility' => 'private']));
    }
}
