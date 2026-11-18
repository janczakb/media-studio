<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Uploads;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\Signature\S3SignatureV4;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use UseFlex\MediaStudio\Storage\Encryption\EncryptionProfiles;

final class S3Multipart
{
    public const TRANSPORT = 's3-multipart';

    private const REQUEST = ['@http' => ['connect_timeout' => 5, 'timeout' => 30], '@retries' => 1];

    public function threshold(object $workspace): ?int
    {
        if (! config('media-studio.upload.sessions.multipart.enabled', false)
            || ! class_exists(S3Client::class)
            || ! class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class)
            || ! in_array(config('filesystems.disks.'.$workspace->disk.'.driver'), ['s3', 'fms-s3-native'], true)) {
            return null;
        }

        $disk = Storage::disk($workspace->disk);
        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }
        $config = $disk->getConfig();
        if (($config['visibility'] ?? null) !== 'private' || ($config['read-only'] ?? false)
            || ! preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/', (string) ($config['bucket'] ?? ''))) {
            return null;
        }

        return max(1, (int) config('media-studio.upload.sessions.multipart.threshold_bytes', 104857600));
    }

    public function chunkBytes(): int
    {
        $bytes = (int) config('media-studio.upload.sessions.multipart.part_bytes', 8388608);
        abort_unless($bytes >= 5242880 && $bytes <= 33554432, 500, 'Invalid multipart size configuration.');

        return $bytes;
    }

    public function target(string $diskName, string $key): string
    {
        $disk = $this->disk($diskName);
        $client = $disk->getClient();

        $target = [(string) $client->getEndpoint(), $client->getRegion(), $disk->getConfig()['bucket'], $disk->path($key)];
        $encryption = EncryptionProfiles::multipartOptions($disk->getConfig());
        if ($encryption !== []) {
            ksort($encryption);
            $target[] = ['encryption' => $encryption];
        }

        return hash('sha256', json_encode($target, JSON_THROW_ON_ERROR));
    }

    public function start(object $row): string
    {
        [$client, $object] = $this->location($row);
        $result = $this->request(fn () => $client->createMultipartUpload($object + EncryptionProfiles::multipartOptions($this->disk($row->disk)->getConfig()) + [
            'ChecksumAlgorithm' => 'SHA256',
            'ContentType' => 'application/octet-stream',
            'Metadata' => ['flex-upload-session' => $row->public_id],
            '@retries' => 0,
        ] + self::REQUEST));
        abort_unless(is_string($result['UploadId']) && $result['UploadId'] !== '', 503, 'Storage did not initiate the multipart upload.');

        return $result['UploadId'];
    }

    /** @return array{url:string, headers:array<string,string>} */
    public function sign(object $row, int $index, int $size, string $sha256): array
    {
        [$client, $object] = $this->location($row);
        $lifetime = min(900, max(30, (int) config('media-studio.upload.sessions.multipart.url_seconds', 300)), strtotime($row->expires_at) - time());
        abort_if($lifetime <= 0, 410, 'Upload session expired.');
        $checksum = base64_encode(hex2bin($sha256));

        $signed = $this->request(function () use ($client, $object, $row, $index, $size, $checksum, $lifetime) {
            $command = $client->getCommand('UploadPart', $object + [
                'UploadId' => $row->provider_upload_id, 'PartNumber' => $index + 1, 'ChecksumSHA256' => $checksum,
            ]);
            $handlers = $command->getHandlerList();
            $handlers->remove('signer');
            $handlers->remove('s3.checksum');
            $request = \Aws\serialize($command)->withHeader('Content-Length', (string) $size);
            // Bind the browser-generated Content-Length to the reservation as well as the part digest.
            $signer = new class('s3', $client->getRegion()) extends S3SignatureV4
            {
                protected function getHeaderBlacklist(): array
                {
                    $headers = parent::getHeaderBlacklist();
                    unset($headers['content-length']);

                    return $headers;
                }
            };

            return $signer->presign($request, $client->getCredentials()->wait(), '+'.$lifetime.' seconds');
        });
        abort_unless($signed->getUri()->getScheme() === 'https' || in_array($signed->getUri()->getHost(), ['localhost', '127.0.0.1', '[::1]'], true), 500, 'Multipart uploads require an HTTPS storage endpoint.');

        return ['url' => (string) $signed->getUri(), 'headers' => ['x-amz-checksum-sha256' => $checksum]];
    }

    /** @param array<int, object> $parts */
    public function finish(object $row, array $parts): void
    {
        [$client, $object] = $this->location($row);
        $upload = $object + ['UploadId' => $row->provider_upload_id];
        $listed = 0;
        $marker = 0;
        $pages = 0;
        $manifest = [];

        try {
            do {
                $this->ensureVerificationTime($row);
                abort_if(++$pages > 20, 503, 'Storage part listing exceeded the page budget.');
                $page = $client->listParts($upload + ['MaxParts' => 1000, 'PartNumberMarker' => (string) $marker] + self::REQUEST);
                foreach ($page['Parts'] ?? [] as $part) {
                    $expected = $parts[$listed] ?? null;
                    $checksum = $expected === null ? '' : base64_encode(hex2bin($expected->sha256));
                    abort_unless($expected !== null && (int) $part['PartNumber'] === $listed + 1
                        && (int) $part['Size'] === (int) $expected->size_bytes
                        && hash_equals($expected->provider_etag, (string) $part['ETag'])
                        && hash_equals($checksum, (string) ($part['ChecksumSHA256'] ?? '')), 409, 'Storage parts do not match the upload receipts.');
                    $manifest[] = ['PartNumber' => ++$listed, 'ETag' => $expected->provider_etag, 'ChecksumSHA256' => $checksum];
                }
                if ($page['IsTruncated'] ?? false) {
                    $next = (int) $page['NextPartNumberMarker'];
                    abort_unless($next > $marker && $next === $listed && $listed < $row->part_count, 503, 'Storage returned an invalid part listing.');
                    $marker = $next;
                }
            } while ($page['IsTruncated'] ?? false);
            abort_unless($listed === count($parts), 409, 'Storage parts are incomplete.');
            $this->ensureVerificationTime($row);
            $client->completeMultipartUpload($upload + [
                'MultipartUpload' => ['Parts' => $manifest], 'IfNoneMatch' => '*',
                '@http' => ['connect_timeout' => 5, 'timeout' => 90], '@retries' => 0,
            ]);
        } catch (AwsException $error) {
            // A response can be lost after completion. The stream verification below still owns publication.
            if ($error->getAwsErrorCode() !== 'NoSuchUpload') {
                $this->unavailable($error);
            }
        }
    }

    /**
     * @param  array<int, object>  $parts
     * @return array{mime: string, sha256: string}
     */
    public function verify(object $row, array $parts): array
    {
        [$client, $object] = $this->location($row);
        $deadline = $this->ensureVerificationTime($row);
        try {
            $result = $client->getObject($object + [
                '@http' => ['stream' => true, 'decode_content' => false, 'connect_timeout' => 5, 'timeout' => 30, 'read_timeout' => 20],
                '@retries' => 0,
            ]);
        } catch (AwsException $error) {
            if ($this->missing($error)) {
                abort(409, 'The staged upload is unavailable.');
            }
            $this->unavailable($error);
        }
        $body = $result['Body'];
        abort_unless($body instanceof StreamInterface, 503, 'Storage returned an invalid upload stream.');
        try {
            EncryptionProfiles::assertObjectMetadata($this->disk($row->disk)->getConfig(), $result);
            abort_unless(($result['Metadata']['flex-upload-session'] ?? null) === $row->public_id
                && (int) $result['ContentLength'] === (int) $row->size_bytes, 422, 'Stored upload does not match the session.');
            $fullHash = hash_init('sha256');
            $sample = '';
            foreach ($parts as $part) {
                $hash = hash_init('sha256');
                $remaining = (int) $part->size_bytes;
                while ($remaining > 0) {
                    abort_if(time() >= $deadline, 503, 'Multipart verification deadline elapsed.');
                    $chunk = $body->read(min(65536, $remaining));
                    if ($chunk === '') {
                        abort(503, 'Storage interrupted the upload verification stream.');
                    }
                    hash_update($hash, $chunk);
                    hash_update($fullHash, $chunk);
                    $remaining -= strlen($chunk);
                    if (strlen($sample) < 65536) {
                        $sample .= substr($chunk, 0, 65536 - strlen($sample));
                    }
                }
                abort_unless(hash_equals($part->sha256, hash_final($hash)), 422, 'Stored upload checksum does not match the session.');
            }
            abort_unless($body->eof() || $body->read(1) === '', 422, 'Stored upload exceeds the session size.');
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($sample);
            abort_unless(is_string($mime), 422, 'The file type could not be identified.');

            return ['mime' => $mime, 'sha256' => hash_final($fullHash)];
        } finally {
            $body->close();
        }
    }

    public function abortProviderUpload(object $row, string $providerId): void
    {
        [$client, $object] = $this->location($row);
        try {
            $client->abortMultipartUpload($object + ['UploadId' => $providerId] + self::REQUEST);
        } catch (AwsException $error) {
            if ($error->getAwsErrorCode() !== 'NoSuchUpload') {
                $this->unavailable($error);
            }
        }
    }

    public function cleanup(object $row): void
    {
        if ($row->provider_upload_id === null) {
            return;
        }
        $this->abortProviderUpload($row, $row->provider_upload_id);
        if ($row->state === 'completed') {
            return;
        }
        [$client, $object] = $this->location($row);
        try {
            $head = $client->headObject($object + self::REQUEST);
            abort_unless(($head['Metadata']['flex-upload-session'] ?? null) === $row->public_id, 409, 'Stored upload does not belong to this session.');
            $match = isset($head['VersionId']) ? ['VersionId' => $head['VersionId']] : ['IfMatch' => $head['ETag']];
            $client->deleteObject($object + $match + self::REQUEST);
        } catch (AwsException $error) {
            if (! $this->missing($error)) {
                $this->unavailable($error);
            }
        }
    }

    private function disk(string $name): AwsS3V3Adapter
    {
        $disk = Storage::disk($name);
        abort_unless($disk instanceof AwsS3V3Adapter && ! ($disk->getConfig()['read-only'] ?? false), 503, 'Multipart storage is unavailable.');

        return $disk;
    }

    /** @return array{S3Client, array{Bucket:string, Key:string}} */
    private function location(object $row): array
    {
        $disk = $this->disk($row->disk);
        abort_unless(hash_equals($row->storage_target, $this->target($row->disk, $row->object_key)), 409, 'Multipart storage changed after this upload was created.');

        return [$disk->getClient(), ['Bucket' => $disk->getConfig()['bucket'], 'Key' => $disk->path($row->object_key)]];
    }

    private function request(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $error) {
            $this->unavailable($error);
        }
    }

    private function ensureVerificationTime(object $row): int
    {
        $deadline = min(strtotime($row->expires_at), strtotime($row->lease_expires_at));
        abort_if(time() >= $deadline, 503, 'Multipart verification deadline elapsed.');

        return $deadline;
    }

    private function missing(AwsException $error): bool
    {
        return in_array($error->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true) || $error->getStatusCode() === 404;
    }

    private function unavailable(Throwable $error): never
    {
        report($error);

        throw new HttpException(503, 'Multipart storage is temporarily unavailable.');
    }
}
