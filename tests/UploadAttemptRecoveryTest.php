<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Uploads\UploadAttempts;

final class UploadAttemptRecoveryTest extends TestCase
{
    private function beginAttempt(string $workspace, string $key = 'recovery-fixture-key'): object
    {
        $row = DB::table('fms_workspaces')->where('public_id', $workspace)->first();

        return app(UploadAttempts::class)->begin(
            $row, new Principal('user', 'member-uuid-001'), hash('sha256', $key), hash('sha256', 'recovery-fixture-content'),
        )['attempt'];
    }

    public function test_expired_writer_cannot_publish_or_delete_a_newer_retry_of_the_same_receipt(): void
    {
        $workspace = $this->workspace();
        $file = UploadedFile::fake()->createWithContent('brief.txt', 'A durable ordinary upload.');
        $key = 'ordinary-recovery-0001';
        $disk = Storage::disk('studio-test');
        $staleKey = null;
        $replacement = null;
        $wrapper = \Mockery::mock($disk);
        $wrapper->shouldReceive('writeStream')->once()->andReturnUsing(function (string $path, $stream, array $options) use ($workspace, $file, $key, $disk, &$staleKey, &$replacement): bool {
            $this->assertSame(0, DB::transactionLevel());
            $staleKey = $path;
            $attempt = DB::table('fms_upload_attempts')->where('object_key', $path)->first();
            $this->assertNotNull($attempt, 'Object ownership must be persisted before the write.');
            DB::table('fms_upload_attempts')->where('id', $attempt->id)->update(['lease_expires_at' => now()->subMinute()]);
            Storage::set('studio-test', $disk);
            $replacement = $this->withHeader('Idempotency-Key', $key)->postJson($this->url($workspace, 'uploads'), ['file' => $file])
                ->assertCreated()->json('id');

            return $disk->writeStream($path, $stream, $options);
        });
        Storage::set('studio-test', $wrapper);

        $this->withHeader('Idempotency-Key', $key)->postJson($this->url($workspace, 'uploads'), ['file' => $file])->assertConflict();

        $this->assertNotNull($replacement);
        $this->assertDatabaseCount('fms_asset_versions', 1);
        $this->assertDatabaseCount('fms_upload_attempts', 2);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $replacement]);
        $this->assertDatabaseHas('fms_upload_receipts', ['state' => 'accepted']);
        $accepted = DB::table('fms_asset_versions')->first();
        $this->assertNotSame($staleKey, $accepted->object_key);
        $this->assertFalse($disk->exists($staleKey));
        $this->assertSame('A durable ordinary upload.', $disk->get($accepted->object_key));
        $this->assertCount(1, $disk->allFiles());
        $this->withHeader('Idempotency-Key', $key)->postJson($this->url($workspace, 'uploads'), ['file' => $file])
            ->assertCreated()->assertJsonPath('id', $replacement);
        $this->assertDatabaseCount('fms_asset_versions', 1);
    }

    public function test_trashed_names_can_be_reused_and_restoring_never_overwrites_an_active_file(): void
    {
        $workspace = $this->workspace();
        $upload = fn (string $content) => $this->withHeader('Idempotency-Key', hash('sha256', $content))->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->createWithContent('brief.txt', $content),
        ]);
        $first = $upload('Original brief.')->assertCreated()->json();
        $this->postJson($this->url($workspace, 'entries/'.$first['id'].'/trash'), ['revision' => $first['revision']])->assertOk();

        $replacement = $upload('Current brief.')->assertCreated()->json();
        $this->assertNotSame($first['id'], $replacement['id']);
        $upload('Another brief.')->assertConflict();

        $restore = $this->url($workspace, 'entries/'.$first['id'].'/restore');
        $this->postJson($restore, ['revision' => $first['revision'] + 1])->assertConflict();
        $this->assertNotNull(DB::table('fms_entries')->where('public_id', $first['id'])->value('trashed_at'));
        $this->assertDatabaseHas('fms_entries', ['public_id' => $replacement['id'], 'revision' => $replacement['revision'], 'trashed_at' => null]);

        $this->postJson($this->url($workspace, 'entries/'.$replacement['id'].'/trash'), ['revision' => $replacement['revision']])->assertOk();
        $this->postJson($restore, ['revision' => $first['revision'] + 1])->assertOk();
        $this->assertDatabaseHas('fms_entries', ['public_id' => $first['id'], 'trashed_at' => null]);
        $this->assertDatabaseCount('fms_asset_versions', 2);
    }

    public function test_active_write_rejects_a_duplicate_request_until_its_lease_expires(): void
    {
        $workspace = $this->workspace();
        $file = UploadedFile::fake()->createWithContent('brief.txt', 'An in-flight upload.');
        $key = 'ordinary-recovery-0002';
        $disk = Storage::disk('studio-test');
        $wrapper = \Mockery::mock($disk);
        $wrapper->shouldReceive('writeStream')->once()->andReturnUsing(function (string $path, $stream, array $options) use ($workspace, $file, $key, $disk): bool {
            Storage::set('studio-test', $disk);
            $this->withHeader('Idempotency-Key', $key)->postJson($this->url($workspace, 'uploads'), ['file' => $file])->assertConflict();

            return $disk->writeStream($path, $stream, $options);
        });
        Storage::set('studio-test', $wrapper);

        $this->withHeader('Idempotency-Key', $key)->postJson($this->url($workspace, 'uploads'), ['file' => $file])->assertCreated();

        $this->assertDatabaseCount('fms_upload_attempts', 1);
        $this->assertDatabaseCount('fms_asset_versions', 1);
        $this->assertDatabaseHas('fms_upload_attempts', ['state' => 'accepted']);
    }

    public function test_crashed_write_cleanup_remains_scheduled_and_removes_a_late_storage_write(): void
    {
        $workspace = $this->workspace();
        $attempt = $this->beginAttempt($workspace);
        $disk = Storage::disk('studio-test');
        $disk->put($attempt->object_key, 'Partial bytes.');
        $disk->put('unrelated-file.txt', 'Keep this file.');
        DB::table('fms_upload_attempts')->where('id', $attempt->id)->update([
            'lease_expires_at' => now()->subMinute(), 'next_cleanup_at' => now()->subMinute(),
        ]);

        $this->artisan('media-studio:uploads:prune-attempts', ['--limit' => 1])->assertSuccessful();

        $this->assertFalse($disk->exists($attempt->object_key));
        $this->assertDatabaseHas('fms_upload_attempts', ['id' => $attempt->id, 'state' => 'abandoned', 'writer_finished_at' => null, 'cleaned_at' => null]);
        $this->assertDatabaseHas('fms_upload_receipts', ['state' => 'failed']);
        $disk->put($attempt->object_key, 'Bytes from a late provider response.');
        $this->travel(61)->minutes();
        $this->artisan('media-studio:uploads:prune-attempts')->assertSuccessful();

        $this->assertFalse($disk->exists($attempt->object_key));
        $this->assertSame('Keep this file.', $disk->get('unrelated-file.txt'));
        $this->assertDatabaseHas('fms_upload_attempts', ['id' => $attempt->id, 'cleanup_attempts' => 2, 'cleaned_at' => null]);
        $this->travelBack();
    }

    public function test_failed_deletion_stays_durable_and_pruner_retries_only_a_bounded_batch(): void
    {
        $workspace = $this->workspace();
        $attempt = $this->beginAttempt($workspace);
        $disk = Storage::disk('studio-test');
        $disk->put($attempt->object_key, 'Failed upload bytes.');
        app(UploadAttempts::class)->fail($attempt);
        $wrapper = \Mockery::mock($disk);
        $wrapper->shouldReceive('delete')->with($attempt->object_key)->once()->andReturn(false);
        Storage::set('studio-test', $wrapper);

        $this->artisan('media-studio:uploads:prune-attempts', ['--limit' => 1])->assertFailed();

        $this->assertTrue($disk->exists($attempt->object_key));
        $this->assertDatabaseHas('fms_upload_attempts', ['id' => $attempt->id, 'state' => 'failed', 'cleaned_at' => null]);
        Storage::set('studio-test', $disk);
        $this->travel(61)->minutes();
        $this->artisan('media-studio:uploads:prune-attempts', ['--limit' => 1])->assertSuccessful();
        $this->assertFalse($disk->exists($attempt->object_key));
        $this->assertNotNull(DB::table('fms_upload_attempts')->where('id', $attempt->id)->value('cleaned_at'));
        $this->travelBack();
    }

    public function test_pruner_caps_each_batch_and_never_selects_an_active_or_accepted_write(): void
    {
        $workspace = $this->workspace();
        $a = $this->beginAttempt($workspace, 'first-key');
        $b = $this->beginAttempt($workspace, 'second-key');
        $active = $this->beginAttempt($workspace, 'active-key');
        $attempts = app(UploadAttempts::class);
        $attempts->fail($a);
        $attempts->fail($b);
        $disk = Storage::disk('studio-test');
        $disk->put($a->object_key, 'First failed object.');
        $disk->put($b->object_key, 'Second failed object.');
        $disk->put($active->object_key, 'Still being written.');

        $this->artisan('media-studio:uploads:prune-attempts', ['--limit' => 1])->assertSuccessful();

        $this->assertSame(1, DB::table('fms_upload_attempts')->whereNotNull('cleaned_at')->count());
        $this->assertCount(2, $disk->allFiles());
        $this->assertTrue($disk->exists($active->object_key));
        $this->artisan('media-studio:uploads:prune-attempts', ['--limit' => 0])->assertExitCode(2);
        $this->assertCount(2, $disk->allFiles());
    }

    public function test_cleanup_refuses_changed_storage_and_preserves_referenced_originals(): void
    {
        $workspace = $this->workspace();
        $file = UploadedFile::fake()->createWithContent('brief.txt', 'An accepted original.');
        $this->withHeader('Idempotency-Key', 'ordinary-recovery-0003')->postJson($this->url($workspace, 'uploads'), ['file' => $file])->assertCreated();
        $accepted = DB::table('fms_upload_attempts')->first();
        DB::table('fms_upload_attempts')->where('id', $accepted->id)->update(['state' => 'failed', 'next_cleanup_at' => now()]);
        $this->artisan('media-studio:uploads:prune-attempts')->assertSuccessful();
        $this->assertTrue(Storage::disk('studio-test')->exists($accepted->object_key));
        $this->assertDatabaseHas('fms_upload_attempts', ['id' => $accepted->id, 'state' => 'retained']);

        $attempt = $this->beginAttempt($workspace);
        Storage::disk('studio-test')->put($attempt->object_key, 'Unpublished bytes.');
        app(UploadAttempts::class)->fail($attempt);
        DB::table('fms_upload_attempts')->where('id', $attempt->id)->update(['storage_target' => str_repeat('0', 64)]);
        $this->artisan('media-studio:uploads:prune-attempts')->assertFailed();
        $this->assertTrue(Storage::disk('studio-test')->exists($attempt->object_key));
        $this->assertDatabaseHas('fms_upload_attempts', ['id' => $attempt->id, 'cleaned_at' => null]);
    }
}
