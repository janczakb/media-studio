<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaStudio\Uploads\UploadSessions;

final class UploadSessionsTest extends TestCase
{
    private string $staging;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staging = sys_get_temp_dir().'/studio-chunks-'.bin2hex(random_bytes(12));
        config()->set('media-studio.upload.sessions', ['enabled' => true, 'chunk_bytes' => 65536, 'max_bytes' => 1048576, 'max_parts' => 16, 'max_active' => 3, 'ttl_seconds' => 3600, 'staging_directory' => $this->staging]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->staging);
        parent::tearDown();
    }

    private function createSession(string $workspace, string $name, int $size, string $key = 'chunk-test-key-000001'): array
    {
        return $this->postJson($this->url($workspace, 'upload-sessions'), ['name' => $name, 'size' => $size, 'key' => $key])->assertCreated()->json();
    }

    private function part(string $workspace, string $session, int $index, string $bytes, ?string $checksum = null)
    {
        return $this->call('PUT', $this->url($workspace, 'upload-sessions/'.$session.'/parts/'.$index), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', ...($checksum === null ? [] : ['HTTP_X_PART_SHA256' => $checksum])], $bytes);
    }

    public function test_chunks_resume_out_of_order_and_finalize_idempotently_without_duplicate_bytes(): void
    {
        $workspace = $this->workspace();
        $bytes = str_repeat('a', 65536).str_repeat('b', 65536).'Final brief';
        $session = $this->createSession($workspace, 'brief.txt', strlen($bytes));
        $id = $session['id'];
        $this->part($workspace, $id, 1, substr($bytes, 65536, 65536))->assertOk();
        $this->part($workspace, $id, 1, substr($bytes, 65536, 65536))->assertOk();
        $this->assertDatabaseCount('fms_upload_parts', 1);
        $this->getJson($this->url($workspace, 'upload-sessions/'.$id))->assertOk()->assertJsonPath('acceptedParts', [1]);
        $this->postJson($this->url($workspace, 'upload-sessions/'.$id.'/complete'))->assertConflict();
        $this->part($workspace, $id, 0, substr($bytes, 0, 65536), hash('sha256', substr($bytes, 0, 65536)))->assertOk();
        $this->part($workspace, $id, 2, substr($bytes, 131072))->assertOk();
        $entry = $this->postJson($this->url($workspace, 'upload-sessions/'.$id.'/complete'))->assertOk()->json('id');
        $this->postJson($this->url($workspace, 'upload-sessions/'.$id.'/complete'))->assertOk()->assertJsonPath('id', $entry);
        $this->assertDatabaseCount('fms_asset_versions', 1);
        $version = DB::table('fms_asset_versions')->first();
        $this->assertSame($bytes, Storage::disk('studio-test')->get($version->object_key));
        $this->assertSame(hash('sha256', $bytes), $version->sha256);
        $this->assertDirectoryDoesNotExist(app(UploadSessions::class)->store()->directory($id));
    }

    public function test_wrong_size_checksum_and_conflicting_retry_never_change_accepted_parts(): void
    {
        $workspace = $this->workspace();
        $session = $this->createSession($workspace, 'small.txt', 3)['id'];
        $this->part($workspace, $session, 0, 'too long')->assertUnprocessable();
        $this->part($workspace, $session, 0, 'a')->assertUnprocessable();
        $this->part($workspace, $session, 0, 'abc', str_repeat('0', 64))->assertUnprocessable();
        $this->assertDatabaseCount('fms_upload_parts', 0);
        $this->part($workspace, $session, 0, 'abc')->assertOk();
        $this->part($workspace, $session, 0, 'xyz')->assertConflict();
        $this->part($workspace, $session, 1, 'abc')->assertConflict();
        $this->postJson($this->url($workspace, 'upload-sessions/'.$session.'/complete'))->assertOk();
        $this->assertSame('abc', Storage::disk('studio-test')->get(DB::table('fms_asset_versions')->value('object_key')));
    }

    public function test_session_scope_and_permission_are_rechecked_during_part_and_completion(): void
    {
        $workspace = $this->workspace();
        $id = $this->createSession($workspace, 'private.txt', 3)['id'];
        $this->part($workspace, $id, 0, 'abc')->assertOk();
        $this->actingAs(new GenericUser(['id' => 'unrelated']));
        $this->getJson($this->url($workspace, 'upload-sessions/'.$id))->assertNotFound();
        $this->part($workspace, $id, 0, 'abc')->assertNotFound();
        $this->actingAs(new GenericUser(['id' => 'member-uuid-001']));
        DB::table('fms_memberships')->update(['role' => 'viewer']);
        $this->postJson($this->url($workspace, 'upload-sessions/'.$id.'/complete'))->assertForbidden();
        $this->assertDatabaseCount('fms_asset_versions', 0);
    }

    public function test_cancel_and_expiry_remove_only_session_staging_and_keep_completed_assets(): void
    {
        $workspace = $this->workspace();
        $id = $this->createSession($workspace, 'cancelled.txt', 3)['id'];
        $this->part($workspace, $id, 0, 'abc')->assertOk();
        $this->deleteJson($this->url($workspace, 'upload-sessions/'.$id))->assertOk();
        $this->deleteJson($this->url($workspace, 'upload-sessions/'.$id))->assertOk();
        $this->postJson($this->url($workspace, 'upload-sessions/'.$id.'/complete'))->assertGone();
        $id = $this->createSession($workspace, 'expired.txt', 3, 'chunk-test-key-000002')['id'];
        $this->part($workspace, $id, 0, 'abc')->assertOk();
        DB::table('fms_upload_sessions')->where('public_id', $id)->update(['expires_at' => now()->subMinute()]);
        $this->getJson($this->url($workspace, 'upload-sessions/'.$id))->assertGone();
        $this->artisan('media-studio:uploads:prune', ['--limit' => 1])->assertSuccessful();
        $this->assertDirectoryDoesNotExist(app(UploadSessions::class)->store()->directory($id));
        $this->assertDatabaseHas('fms_upload_sessions', ['public_id' => $id, 'state' => 'expired']);
    }

    public function test_admission_limits_and_idempotency_prevent_unbounded_sessions(): void
    {
        $workspace = $this->workspace();
        $first = $this->createSession($workspace, 'one.txt', 3);
        $this->createSession($workspace, 'one.txt', 3);
        $this->assertDatabaseCount('fms_upload_sessions', 1);
        $this->postJson($this->url($workspace, 'upload-sessions'), ['name' => 'other.txt', 'size' => 3, 'key' => 'chunk-test-key-000001'])->assertConflict();
        $this->createSession($workspace, 'two.txt', 3, 'chunk-test-key-000002');
        $this->createSession($workspace, 'three.txt', 3, 'chunk-test-key-000003');
        $this->postJson($this->url($workspace, 'upload-sessions'), ['name' => 'four.txt', 'size' => 3, 'key' => 'chunk-test-key-000004'])->assertTooManyRequests();
        $this->postJson($this->url($workspace, 'upload-sessions'), ['name' => 'large.txt', 'size' => 1048577, 'key' => 'chunk-test-key-000005'])->assertUnprocessable();
        $this->postJson($this->url($workspace, 'upload-sessions'), ['name' => '../file.txt', 'size' => 3, 'key' => 'chunk-test-key-000006'])->assertUnprocessable();
        $this->getJson($this->url($workspace, 'upload-sessions/'.$first['id']))->assertJsonPath('state', 'uploading');
    }

    public function test_finalization_streams_outside_sql_transaction_and_lease_is_fenced_at_publish(): void
    {
        $workspace = $this->workspace();
        $id = $this->createSession($workspace, 'safe.txt', 3)['id'];
        $this->part($workspace, $id, 0, 'abc')->assertOk();
        $disk = Storage::disk('studio-test');
        $wrapper = \Mockery::mock($disk);
        $wrapper->shouldReceive('writeStream')->once()->andReturnUsing(function (string $path, $stream, array $options) use ($disk, $id): bool {
            $this->assertSame(0, DB::transactionLevel(), 'Storage writes must not hold a SQL transaction.');
            $this->assertDatabaseHas('fms_upload_sessions', ['public_id' => $id, 'state' => 'finalizing']);
            DB::table('fms_upload_sessions')->where('public_id', $id)->update(['lease_token' => str_repeat('0', 64)]);

            return $disk->writeStream($path, $stream, $options);
        });
        Storage::set('studio-test', $wrapper);
        $this->postJson($this->url($workspace, 'upload-sessions/'.$id.'/complete'))->assertConflict();
        $this->assertDatabaseCount('fms_asset_versions', 0);
        $this->assertSame([], $disk->allFiles());
    }

    public function test_finalizing_lease_blocks_cancel_and_pruning_until_expired(): void
    {
        $workspace = $this->workspace();
        $id = $this->createSession($workspace, 'running.txt', 3)['id'];
        $this->part($workspace, $id, 0, 'abc')->assertOk();
        DB::table('fms_upload_sessions')->where('public_id', $id)->update(['state' => 'finalizing', 'lease_token' => str_repeat('1', 64), 'lease_expires_at' => now()->addHour()]);
        $this->deleteJson($this->url($workspace, 'upload-sessions/'.$id))->assertConflict();
        $this->postJson($this->url($workspace, 'upload-sessions/'.$id.'/complete'))->assertConflict();
        $this->artisan('media-studio:uploads:prune')->assertSuccessful();
        $this->assertDirectoryExists(app(UploadSessions::class)->store()->directory($id));
        DB::table('fms_upload_sessions')->where('public_id', $id)->update(['lease_expires_at' => now()->subMinute()]);
        $this->artisan('media-studio:uploads:prune')->assertSuccessful();
        $this->assertDirectoryDoesNotExist(app(UploadSessions::class)->store()->directory($id));
        $this->assertDatabaseHas('fms_upload_sessions', ['public_id' => $id, 'state' => 'expired']);
    }
}
