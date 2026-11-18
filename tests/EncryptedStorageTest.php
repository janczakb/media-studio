<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\InvalidVisibilityProvided;
use UseFlex\MediaCore\Encryption\EncryptionFailure;
use UseFlex\MediaStudio\Http\MediaResponse;

final class EncryptedStorageTest extends TestCase
{
    private function disk(array $keys = [], string $active = 'old'): mixed
    {
        config(['media-studio.encryption.mode' => 'application', 'media-studio.encryption.experimental_file_driver' => true, 'media-studio.encryption.active_key' => $active,
            'media-studio.encryption.keys' => $keys ?: ['old' => 'base64:'.base64_encode(str_repeat('a', 32))],
            'filesystems.disks.encrypted-test' => ['driver' => 'fms-encrypted', 'backing_disk' => 'studio-test', 'scope' => 'test-app-content', 'segment_bytes' => 4096, 'throw' => true],
            'filesystems.disks.studio-test.driver' => 'local']);
        Storage::forgetDisk('encrypted-test');

        return Storage::disk('encrypted-test');
    }

    public function test_plaintext_is_not_stored_and_streams_seek_without_plaintext_spooling(): void
    {
        $disk = $this->disk();
        $plain = str_repeat('private PDF content ', 3000);
        self::assertTrue($disk->put('workspace/object', $plain));
        $cipher = Storage::disk('studio-test')->get('workspace/object');
        self::assertStringStartsWith('FMSENC01', $cipher);
        self::assertStringNotContainsString('private PDF content', $cipher);
        self::assertSame(strlen($plain), $disk->size('workspace/object'));
        self::assertSame($plain, $disk->get('workspace/object'));
        $stream = $disk->readStream('workspace/object');
        try {
            self::assertSame(strlen($plain), fstat($stream)['size']);
            self::assertSame(0, fseek($stream, 20000));
            self::assertSame(substr($plain, 20000, 5000), fread($stream, 5000));
            self::assertSame(0, fseek($stream, -12, SEEK_END));
            self::assertSame(substr($plain, -12), stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
        self::assertSame('private', $disk->getVisibility('workspace/object'));
        self::assertSame(['workspace/object'], $disk->allFiles('workspace'));
    }

    public function test_copy_move_and_key_rotation_preserve_content_and_bind_object_identity(): void
    {
        $old = 'base64:'.base64_encode(str_repeat('a', 32));
        $new = 'base64:'.base64_encode(str_repeat('b', 32));
        $disk = $this->disk(['old' => $old]);
        $disk->put('first', 'private content');
        $original = Storage::disk('studio-test')->get('first');
        $disk = $this->disk(['old' => $old, 'new' => $new], 'new');
        self::assertSame('private content', $disk->get('first'));
        self::assertTrue($disk->copy('first', 'second'));
        self::assertSame('private content', $disk->get('second'));
        self::assertNotSame($original, Storage::disk('studio-test')->get('second'));
        self::assertTrue($disk->move('second', 'third'));
        self::assertFalse($disk->exists('second'));
        self::assertSame('private content', $disk->get('third'));
        Storage::disk('studio-test')->put('substituted', $original);
        try {
            $disk->get('substituted');
            self::fail('Moving ciphertext to a different object identity must fail.');
        } catch (EncryptionFailure) {
            self::assertTrue(true);
        }
        $disk = $this->disk(['new' => $new], 'new');
        self::assertSame('private content', $disk->get('third'));
        $this->expectException(EncryptionFailure::class);
        $disk->get('first');
    }

    public function test_plaintext_fallback_and_public_visibility_are_rejected(): void
    {
        $disk = $this->disk();
        Storage::disk('studio-test')->put('plaintext', str_repeat('secret', 20));
        try {
            $disk->get('plaintext');
            self::fail('Plaintext fallback is prohibited.');
        } catch (EncryptionFailure) {
            self::assertTrue(true);
        }
        $this->expectException(InvalidVisibilityProvided::class);
        $disk->put('public', 'secret', 'public');
    }

    public function test_http_range_returns_authenticated_plaintext(): void
    {
        $disk = $this->disk();
        $plain = str_repeat('PDF data ', 3000);
        $disk->put('pdf', $plain);
        $response = app(MediaResponse::class)->stream(Request::create('/file', server: ['HTTP_RANGE' => 'bytes=18000-18199']), (object) [
            'disk' => 'encrypted-test', 'object_key' => 'pdf', 'size_bytes' => strlen($plain), 'mime_type' => 'application/pdf', 'name' => 'file.pdf',
        ]);
        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 18000-18199/'.strlen($plain), $response->headers->get('Content-Range'));
        ob_start();
        $response->sendContent();
        self::assertSame(substr($plain, 18000, 200), ob_get_clean());
    }

    public function test_missing_keys_and_unreviewed_driver_are_not_silently_enabled(): void
    {
        config(['filesystems.disks.encrypted-disabled' => ['driver' => 'fms-encrypted', 'backing_disk' => 'studio-test', 'scope' => 'test']]);
        $this->expectException(EncryptionFailure::class);
        Storage::disk('encrypted-disabled');
    }

    public function test_real_upload_pipeline_keeps_ciphertext_on_disk(): void
    {
        $disk = $this->disk();
        config(['media-studio.storage.disk' => 'encrypted-test']);
        $workspace = $this->workspace('Encrypted');
        $plain = str_repeat('Confidential upload content. ', 900);
        $entry = $this->withHeader('Idempotency-Key', 'encryption-upload-test-0001')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->createWithContent('private.txt', $plain),
        ])->assertCreated()->json('id');
        $version = DB::table('fms_asset_versions')->join('fms_entries', 'fms_entries.current_version_id', '=', 'fms_asset_versions.id')
            ->where('fms_entries.public_id', $entry)->first(['fms_asset_versions.*']);
        self::assertSame('encrypted-test', $version->disk);
        self::assertSame(strlen($plain), (int) $version->size_bytes);
        self::assertSame(hash('sha256', $plain), $version->sha256);
        self::assertSame($plain, $disk->get($version->object_key));
        self::assertStringNotContainsString('Confidential upload content.', Storage::disk('studio-test')->get($version->object_key));
    }

    public function test_corrupt_tail_is_rejected_before_a_stream_is_returned(): void
    {
        $disk = $this->disk();
        $disk->put('corrupt', str_repeat('private', 5000));
        $cipher = Storage::disk('studio-test')->get('corrupt');
        Storage::disk('studio-test')->put('corrupt', substr($cipher, 0, -1));
        $this->expectException(EncryptionFailure::class);
        $disk->readStream('corrupt');
    }
}
