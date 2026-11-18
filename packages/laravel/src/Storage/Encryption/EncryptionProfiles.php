<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use UseFlex\MediaCore\Encryption\EncryptionFailure;

/** Deployment policy. Configuring a provider is not proof that all stored data is encrypted. */
final class EncryptionProfiles
{
    public function mode(): string
    {
        $mode = config('media-studio.encryption.mode', 'off');
        if (! in_array($mode, ['off', 'application', 'infrastructure', 'hybrid'], true)) {
            throw new EncryptionFailure('Unknown Studio encryption mode.');
        }

        return $mode;
    }

    /** Compile the explicit native disk policies before any filesystem is resolved. */
    public function configure(): void
    {
        $mode = $this->mode();
        if ($mode === 'off') {
            return; // Never disable encryption already configured by the host application.
        }
        $policies = config('media-studio.encryption.disks', []);
        if (! is_array($policies) || count($policies) > 64) {
            throw new EncryptionFailure('Configure at most 64 encrypted storage policies.');
        }
        if ($mode === 'application' && $policies !== []) {
            throw new EncryptionFailure('Select hybrid mode to combine application and native storage encryption.');
        }
        $disks = config('filesystems.disks', []);
        $compiled = [];
        foreach ($policies as $name => $policy) {
            if (! is_string($name) || ! isset($disks[$name]) || ! is_array($disks[$name]) || ! is_array($policy)) {
                throw new EncryptionFailure('An encryption policy references an invalid disk.');
            }
            $disk = $disks[$name];
            if (($disk['visibility'] ?? 'private') !== 'private') {
                throw new EncryptionFailure('Encrypted Studio storage must be private.');
            }
            $driver = $policy['driver'] ?? '';
            $allowed = in_array($driver, ['provider-default', 'encrypted-volume'], true) ? ['driver'] : ['driver', 'kms_key_id', 'bucket_key'];
            if (array_diff(array_keys($policy), $allowed) !== []) {
                throw new EncryptionFailure('Unknown encryption policy option.');
            }
            if ($driver === 'provider-default') {
                continue; // Inherit exactly; make no claim about unverified provider defaults.
            }
            if ($driver === 'encrypted-volume') {
                if (($disk['driver'] ?? '') !== 'local') {
                    throw new EncryptionFailure('An encrypted-volume policy requires a local disk.');
                }

                continue; // Volume setup and verification are outside a PHP package's authority.
            }
            if (! in_array($disk['driver'] ?? '', ['s3', 'fms-s3-native'], true)) {
                throw new EncryptionFailure('Native SSE policies require an S3 disk.');
            }
            if (($disk['scheme'] ?? 'https') !== 'https' || ($disk['http']['verify'] ?? true) === false) {
                throw new EncryptionFailure('Native encrypted S3 profiles require verified HTTPS.');
            }
            if (isset($disk['endpoint'])) {
                $endpoint = parse_url((string) $disk['endpoint']);
                if (! is_array($endpoint) || ($endpoint['scheme'] ?? '') !== 'https' || empty($endpoint['host']) || isset($endpoint['user']) || isset($endpoint['pass'])) {
                    throw new EncryptionFailure('Encrypted S3 endpoints must use HTTPS without embedded credentials.');
                }
            }
            $options = $this->options($policy);
            if (array_intersect_key((array) ($disk['options'] ?? []), array_flip(['SSECustomerKey', 'SSECustomerAlgorithm', 'SSECustomerKeyMD5'])) !== []) {
                throw new EncryptionFailure('SSE-C cannot be combined with a Studio SSE profile.');
            }
            // Remove conflicting managed fields before applying a complete, validated policy.
            $managed = array_flip(['ServerSideEncryption', 'SSEKMSKeyId', 'SSEKMSEncryptionContext', 'BucketKeyEnabled']);
            $existing = array_diff_key((array) ($disk['options'] ?? []), $managed);
            if (isset($existing['params'])) {
                if (! is_array($existing['params'])) {
                    throw new EncryptionFailure('Invalid S3 upload parameters.');
                }
                $existing['params'] = array_diff_key($existing['params'], $managed);
            }
            foreach ([$existing, (array) ($existing['params'] ?? [])] as $parameters) {
                if (isset($parameters['ACL']) && ! in_array($parameters['ACL'], ['private', 'bucket-owner-full-control'], true)) {
                    throw new EncryptionFailure('Private encrypted storage cannot use a public ACL.');
                }
                if (array_intersect_key($parameters, array_flip(['GrantFullControl', 'GrantRead', 'GrantReadACP', 'GrantWriteACP', 'SSECustomerAlgorithm', 'SSECustomerKey', 'SSECustomerKeyMD5'])) !== []) {
                    throw new EncryptionFailure('Explicit ACL grants or SSE-C conflict with the native encryption policy.');
                }
            }
            $compiled[$name] = array_replace($disk, ['driver' => 'fms-s3-native', 'options' => $existing + $options, 'throw' => true, 'visibility' => 'private']);
        }
        foreach ($compiled as $name => $disk) {
            $disks[$name] = $disk;
        }
        config(['filesystems.disks' => $disks]);
    }

    /** Options for SDK multipart initiation, which bypasses Flysystem's write defaults. */
    public static function multipartOptions(array $disk): array
    {
        $options = (array) ($disk['options'] ?? []);
        if (isset($options['SSECustomerKey']) || isset($options['SSECustomerAlgorithm'])) {
            throw new EncryptionFailure('SSE-C multipart transfers require a dedicated implementation.');
        }

        return array_intersect_key($options, array_flip(['ServerSideEncryption', 'SSEKMSKeyId', 'SSEKMSEncryptionContext', 'BucketKeyEnabled']));
    }

    /** Verify the actual stored object's encryption metadata, not a UI/configuration flag. */
    public static function assertObjectMetadata(array $disk, mixed $metadata): void
    {
        $options = self::multipartOptions($disk);
        $algorithm = $options['ServerSideEncryption'] ?? null;
        if ($algorithm !== null && ($metadata['ServerSideEncryption'] ?? null) !== $algorithm) {
            throw new EncryptionFailure('Storage did not confirm the requested object encryption.');
        }
        if (isset($options['SSEKMSKeyId']) && ($metadata['SSEKMSKeyId'] ?? null) !== $options['SSEKMSKeyId']) {
            throw new EncryptionFailure('Storage did not confirm the configured KMS key.');
        }
    }

    private function options(array $policy): array
    {
        $driver = $policy['driver'] ?? '';
        if (! in_array($driver, ['sse-s3', 'sse-kms', 'dsse-kms'], true)) {
            throw new EncryptionFailure('Unknown native encryption profile.');
        }
        if ($driver === 'sse-s3') {
            if (isset($policy['kms_key_id']) || isset($policy['bucket_key'])) {
                throw new EncryptionFailure('SSE-S3 does not accept KMS options.');
            }

            return ['ServerSideEncryption' => 'AES256'];
        }
        $key = $policy['kms_key_id'] ?? '';
        // Canonical key ARN permits exact comparison against S3 response metadata.
        if (! is_string($key) || ! preg_match('~\Aarn:aws(?:-cn|-us-gov)?:kms:[a-z0-9-]+:[0-9]{12}:key/[a-zA-Z0-9-]{1,256}\z~', $key)) {
            throw new EncryptionFailure('SSE-KMS requires a canonical KMS key ARN.');
        }
        $options = ['ServerSideEncryption' => $driver === 'sse-kms' ? 'aws:kms' : 'aws:kms:dsse', 'SSEKMSKeyId' => $key];
        if (array_key_exists('bucket_key', $policy)) {
            if ($driver !== 'sse-kms' || ! is_bool($policy['bucket_key'])) {
                throw new EncryptionFailure('S3 Bucket Keys are available only for SSE-KMS.');
            }
            $options['BucketKeyEnabled'] = $policy['bucket_key'];
        }

        return $options;
    }
}
