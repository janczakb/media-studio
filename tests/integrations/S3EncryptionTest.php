<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use Psr\Http\Message\RequestInterface;
use UseFlex\MediaStudio\Storage\Encryption\ContentKeyring;
use UseFlex\MediaStudio\Storage\Encryption\EncryptedFilesystemAdapter;
use UseFlex\MediaStudio\Storage\Encryption\EncryptionProfiles;
use UseFlex\MediaStudio\Uploads\S3Multipart;

final class S3EncryptionTest extends TestCase
{
    private const KMS = 'arn:aws:kms:eu-central-1:123456789012:key/12345678-1234-1234-1234-123456789012';

    public function test_native_sse_kms_is_sent_by_flysystem_and_multipart_sdk_requests(): void
    {
        $handler = new MockHandler;
        config(['filesystems.disks.encrypted-s3' => [
            'driver' => 's3', 'visibility' => 'private', 'bucket' => 'private-studio',
            'region' => 'eu-central-1', 'endpoint' => 'https://storage.example.invalid',
            'key' => 'public-test-key', 'secret' => 'public-test-secret', 'handler' => $handler, 'retries' => 0,
            'options' => ['params' => ['ServerSideEncryption' => 'AES256', 'CacheControl' => 'private']],
        ], 'media-studio.encryption.mode' => 'infrastructure', 'media-studio.encryption.disks' => [
            'encrypted-s3' => ['driver' => 'sse-kms', 'kms_key_id' => self::KMS, 'bucket_key' => true],
        ]]);
        app(EncryptionProfiles::class)->configure();
        $handler->append(function (CommandInterface $command, RequestInterface $request): Result {
            self::assertSame('PutObject', $command->getName());
            self::assertSame('private', $request->getHeaderLine('Cache-Control'));
            self::assertSame('aws:kms', $request->getHeaderLine('x-amz-server-side-encryption'));
            self::assertSame(self::KMS, $request->getHeaderLine('x-amz-server-side-encryption-aws-kms-key-id'));
            self::assertSame('true', $request->getHeaderLine('x-amz-server-side-encryption-bucket-key-enabled'));

            return new Result(['ETag' => 'fixture']);
        });
        self::assertTrue(Storage::disk('encrypted-s3')->put('original', 'public integration fixture'));
        $handler->append(function (CommandInterface $command, RequestInterface $request): Result {
            self::assertSame('CreateMultipartUpload', $command->getName());
            self::assertSame('aws:kms', $request->getHeaderLine('x-amz-server-side-encryption'));
            self::assertSame(self::KMS, $request->getHeaderLine('x-amz-server-side-encryption-aws-kms-key-id'));
            self::assertSame('true', $request->getHeaderLine('x-amz-server-side-encryption-bucket-key-enabled'));

            return new Result(['UploadId' => 'kms-multipart-fixture']);
        });
        $multipart = app(S3Multipart::class);
        $target = $multipart->target('encrypted-s3', 'large-original');
        self::assertSame('kms-multipart-fixture', $multipart->start((object) [
            'disk' => 'encrypted-s3', 'object_key' => 'large-original', 'storage_target' => $target, 'public_id' => 'public-session-fixture',
        ]));
        $handler->append(function (CommandInterface $command, RequestInterface $request): Result {
            self::assertSame('CopyObject', $command->getName());
            self::assertSame('aws:kms', $request->getHeaderLine('x-amz-server-side-encryption'));
            self::assertSame(self::KMS, $request->getHeaderLine('x-amz-server-side-encryption-aws-kms-key-id'));

            return new Result;
        });
        Storage::disk('encrypted-s3')->getClient()->copyObject(['Bucket' => 'private-studio', 'Key' => 'copied', 'CopySource' => 'private-studio/original', 'ServerSideEncryption' => 'AES256']);
        config(['filesystems.disks.encrypted-s3.options.SSEKMSKeyId' => self::KMS.'-rotated']);
        Storage::forgetDisk('encrypted-s3');
        self::assertNotSame($target, $multipart->target('encrypted-s3', 'large-original'));
        self::assertSame(0, $handler->count());
    }

    public function test_application_encrypted_s3_reads_only_authenticated_requested_ranges(): void
    {
        $keys = new ContentKeyring(['v1' => 'base64:'.base64_encode(str_repeat('c', 32))], 'v1');
        $local = new EncryptedFilesystemAdapter(Storage::disk('studio-test'), $keys, 'isolated-range-fixture', 4096);
        $plain = str_repeat('public encrypted-range fixture ', 4000);
        $local->write('record', $plain, new Config);
        $cipher = Storage::disk('studio-test')->get('record');
        $ranges = [];
        $handler = new MockHandler;
        $response = static function (CommandInterface $command, RequestInterface $request) use ($cipher, &$ranges): Result {
            if ($command->getName() === 'HeadObject') {
                return new Result(['ContentLength' => strlen($cipher)]);
            }
            self::assertSame('GetObject', $command->getName());
            self::assertSame(1, preg_match('/\Abytes=([0-9]+)-([0-9]+)\z/', $request->getHeaderLine('Range'), $parts));
            $start = (int) $parts[1];
            $length = (int) $parts[2] - $start + 1;
            self::assertLessThanOrEqual(4096, $length);
            $ranges[] = [$start, $length];

            return new Result(['ContentLength' => $length, 'ContentRange' => 'bytes '.$start.'-'.$parts[2].'/'.strlen($cipher), 'Body' => Utils::streamFor(substr($cipher, $start, $length))]);
        };
        for ($i = 0; $i < 8; $i++) {
            $handler->append($response);
        }
        config(['filesystems.disks.range-fixture' => [
            'driver' => 's3', 'visibility' => 'private', 'bucket' => 'private-studio', 'region' => 'eu-central-1',
            'endpoint' => 'https://storage.example.invalid', 'key' => 'fixture', 'secret' => 'fixture', 'handler' => $handler,
        ]]);
        $encrypted = new EncryptedFilesystemAdapter(Storage::disk('range-fixture'), $keys, 'isolated-range-fixture', 4096);
        $stream = $encrypted->readStream('record');
        try {
            self::assertSame(0, fseek($stream, 40000));
            self::assertSame(substr($plain, 40000, 17), fread($stream, 17));
        } finally {
            fclose($stream);
        }
        self::assertLessThanOrEqual(4 * 4096 + 81, array_sum(array_column($ranges, 1))); // Header, final segment and PHP's bounded read-ahead.
        self::assertGreaterThan(100000, strlen($cipher));
    }
}
