<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use UseFlex\MediaCore\Encryption\EncryptionFailure;
use UseFlex\MediaStudio\Storage\Encryption\EncryptionProfiles;

final class EncryptionProfilesTest extends TestCase
{
    private const KMS = 'arn:aws:kms:eu-central-1:123456789012:key/12345678-1234-1234-1234-123456789012';

    public function test_off_preserves_host_encryption_and_ignores_unselected_profiles(): void
    {
        config(['filesystems.disks.native' => ['driver' => 's3', 'options' => ['ServerSideEncryption' => 'AES256']], 'media-studio.encryption.disks' => ['missing' => ['driver' => 'invalid']]]);
        self::assertSame('off', app(EncryptionProfiles::class)->mode());
        app(EncryptionProfiles::class)->configure();
        self::assertSame('AES256', config('filesystems.disks.native.options.ServerSideEncryption'));
    }

    public function test_native_profiles_compile_without_accessing_provider_or_changing_other_disks(): void
    {
        $sentinel = ['driver' => 'local', 'root' => '/unrelated-host-app'];
        config(['media-studio.encryption.mode' => 'infrastructure', 'media-studio.encryption.disks' => [
            'managed' => ['driver' => 'sse-s3'],
            'kms' => ['driver' => 'sse-kms', 'kms_key_id' => self::KMS, 'bucket_key' => true],
            'dual' => ['driver' => 'dsse-kms', 'kms_key_id' => self::KMS],
            'volume' => ['driver' => 'encrypted-volume'],
            'existing' => ['driver' => 'provider-default'],
        ], 'filesystems.disks' => [
            'managed' => ['driver' => 's3', 'options' => ['CacheControl' => 'private', 'SSEKMSKeyId' => 'old-key']],
            'kms' => ['driver' => 's3'], 'dual' => ['driver' => 's3', 'options' => ['BucketKeyEnabled' => true]],
            'volume' => ['driver' => 'local'], 'existing' => ['driver' => 's3'], 'unrelated' => $sentinel,
        ]]);
        app(EncryptionProfiles::class)->configure();
        self::assertSame(['CacheControl' => 'private', 'ServerSideEncryption' => 'AES256'], config('filesystems.disks.managed.options'));
        self::assertSame(['ServerSideEncryption' => 'aws:kms', 'SSEKMSKeyId' => self::KMS, 'BucketKeyEnabled' => true], config('filesystems.disks.kms.options'));
        self::assertSame(['ServerSideEncryption' => 'aws:kms:dsse', 'SSEKMSKeyId' => self::KMS], config('filesystems.disks.dual.options'));
        self::assertSame($sentinel, config('filesystems.disks.unrelated'));
        self::assertSame(['driver' => 'local'], config('filesystems.disks.volume'));
        self::assertSame(['driver' => 's3'], config('filesystems.disks.existing'));
    }

    public function test_invalid_policy_is_rejected_before_any_disk_is_changed(): void
    {
        config(['media-studio.encryption.mode' => 'hybrid', 'filesystems.disks.native' => ['driver' => 's3'], 'filesystems.disks.bad' => ['driver' => 's3'], 'media-studio.encryption.disks' => [
            'native' => ['driver' => 'sse-s3'], 'bad' => ['driver' => 'sse-kms', 'kms_key_id' => 'alias/unknown'],
        ]]);
        try {
            app(EncryptionProfiles::class)->configure();
            self::fail('Noncanonical KMS key was accepted.');
        } catch (EncryptionFailure) {
            self::assertSame(['driver' => 's3'], config('filesystems.disks.native'));
        }
        config(['media-studio.encryption.disks.bad' => ['driver' => 'dsse-kms', 'kms_key_id' => self::KMS, 'bucket_key' => true]]);
        $this->expectException(EncryptionFailure::class);
        app(EncryptionProfiles::class)->configure();
    }

    public function test_multipart_inherits_encryption_options_even_when_studio_mode_is_off(): void
    {
        $disk = ['options' => ['ServerSideEncryption' => 'aws:kms', 'SSEKMSKeyId' => self::KMS, 'BucketKeyEnabled' => true, 'visibility' => 'private', 'CacheControl' => 'private']];
        self::assertSame(['ServerSideEncryption' => 'aws:kms', 'SSEKMSKeyId' => self::KMS, 'BucketKeyEnabled' => true], EncryptionProfiles::multipartOptions($disk));
        EncryptionProfiles::assertObjectMetadata($disk, ['ServerSideEncryption' => 'aws:kms', 'SSEKMSKeyId' => self::KMS]);
        $this->expectException(EncryptionFailure::class);
        EncryptionProfiles::assertObjectMetadata($disk, ['ServerSideEncryption' => 'AES256']);
    }

    public function test_matching_algorithm_with_the_wrong_kms_key_is_rejected(): void
    {
        $this->expectException(EncryptionFailure::class);
        EncryptionProfiles::assertObjectMetadata(['options' => ['ServerSideEncryption' => 'aws:kms', 'SSEKMSKeyId' => self::KMS]], ['ServerSideEncryption' => 'aws:kms', 'SSEKMSKeyId' => self::KMS.'-other']);
    }

    public function test_native_profiles_reject_insecure_transport_or_public_acl_grants(): void
    {
        foreach ([['endpoint' => 'http://storage.example.invalid'], ['http' => ['verify' => false]], ['scheme' => 'http'], ['options' => ['params' => ['ACL' => 'public-read']]], ['options' => ['GrantRead' => 'public']]] as $insecure) {
            config(['media-studio.encryption.mode' => 'infrastructure', 'media-studio.encryption.disks' => ['native' => ['driver' => 'sse-s3']], 'filesystems.disks.native' => ['driver' => 's3'] + $insecure]);
            try {
                app(EncryptionProfiles::class)->configure();
                self::fail('Unverified transport must not be accepted by an explicit native encryption profile.');
            } catch (EncryptionFailure) {
                self::assertSame('s3', config('filesystems.disks.native.driver'));
            }
        }
    }
}
