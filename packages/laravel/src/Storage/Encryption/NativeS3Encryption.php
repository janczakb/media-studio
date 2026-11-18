<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use Aws\CommandInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;

/** Enforce complete SSE parameters on SDK writes, including Flysystem copy/multipart. */
final class NativeS3Encryption
{
    public static function make(object $app, array $config): AwsS3V3Adapter
    {
        $disk = $app['filesystem']->createS3Driver($config);
        $options = EncryptionProfiles::multipartOptions($config);
        $disk->getClient()->getHandlerList()->appendInit(static function (callable $next) use ($options): callable {
            return static function (CommandInterface $command, $request = null) use ($next, $options) {
                if (in_array($command->getName(), ['PutObject', 'CopyObject', 'CreateMultipartUpload'], true)) {
                    foreach ($options as $key => $value) {
                        $command[$key] = $value;
                    }
                }

                return $next($command, $request);
            };
        }, 'fms.s3.encryption');

        return $disk;
    }
}
