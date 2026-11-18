<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaCore\Encryption\EncryptionFailure;
use UseFlex\MediaStudio\Storage\Encryption\FileEncryptionMigration;

final class FileEncryptionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('ciphertext-test');
        config(['media-studio.encryption.mode' => 'application', 'media-studio.encryption.experimental_file_driver' => true,
            'media-studio.encryption.keys' => ['v1' => 'base64:'.base64_encode(str_repeat('m', 32))],
            'filesystems.disks.ciphertext-test.driver' => 'local',
            'filesystems.disks.encrypted-destination' => ['driver' => 'fms-encrypted', 'backing_disk' => 'ciphertext-test', 'scope' => 'migration-fixture', 'segment_bytes' => 4096]]);
    }

    private function uploaded(string $workspace): object
    {
        $id = $this->withHeader('Idempotency-Key', 'migration-file-fixture-0001')->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->createWithContent('private.txt', str_repeat('migration secret ', 1000))])->assertCreated()->json('id');
        $entry = DB::table('fms_entries')->where('public_id', $id)->first();
        DB::table('fms_renditions')->where('entry_id', $entry->id)->update(['state' => 'failed']);

        return DB::table('fms_asset_versions')->where('id', $entry->current_version_id)->first();
    }

    public function test_verified_migration_resumes_blocks_writes_and_preserves_recovery_copy(): void
    {
        $workspace = $this->workspace();
        $original = $this->uploaded($workspace);
        $migration = app(FileEncryptionMigration::class);
        $id = $migration->start($workspace, 'encrypted-destination');
        $this->postJson($this->url($workspace, 'folders'), ['name' => 'Blocked'])->assertStatus(423);
        $first = $migration->step($id, 1);
        self::assertSame('pending', $first->state);
        $version = DB::table('fms_asset_versions')->where('id', $original->id)->first();
        self::assertSame('encrypted-destination', $version->disk);
        self::assertSame($original->sha256, hash('sha256', Storage::disk($version->disk)->get($version->object_key)));
        self::assertStringNotContainsString('migration secret', Storage::disk('ciphertext-test')->get($version->object_key));
        self::assertTrue(Storage::disk($original->disk)->exists($original->object_key));
        $finished = $migration->step($id, 1);
        self::assertSame('completed', $finished->state);
        $w = DB::table('fms_workspaces')->where('public_id', $workspace)->first();
        self::assertSame('encrypted-destination', $w->disk);
        self::assertNull($w->encryption_operation);
        $this->postJson($this->url($workspace, 'folders'), ['name' => 'Available'])->assertCreated();
        self::assertSame(1, DB::table('fms_encryption_copies')->where('operation_id', $id)->count());
    }

    public function test_source_corruption_cannot_publish_a_new_pointer(): void
    {
        $workspace = $this->workspace();
        $original = $this->uploaded($workspace);
        $originalBytes = Storage::disk($original->disk)->get($original->object_key);
        Storage::disk($original->disk)->put($original->object_key, 'corrupted original');
        $migration = app(FileEncryptionMigration::class);
        $id = $migration->start($workspace, 'encrypted-destination');
        try {
            $migration->step($id);
            self::fail('Corrupt original published');
        } catch (EncryptionFailure) {
            $version = DB::table('fms_asset_versions')->where('id', $original->id)->first();
            self::assertSame($original->disk, $version->disk);
            self::assertSame($original->object_key, $version->object_key);
            self::assertSame('failed', DB::table('fms_encryption_operations')->where('id', $id)->value('state'));
            self::assertSame($id, DB::table('fms_workspaces')->where('public_id', $workspace)->value('encryption_operation'));
            Storage::disk($original->disk)->put($original->object_key, $originalBytes);
            $migration->step($id, 1);
            self::assertSame('completed', $migration->step($id, 1)->state);
            self::assertSame(1, DB::table('fms_encryption_copies')->where('operation_id', $id)->count());
        }
    }
}
