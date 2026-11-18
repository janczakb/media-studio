<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\RequestInterface;

final class S3MultipartTest extends TestCase
{
    private MockHandler $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new MockHandler;
        config()->set('filesystems.disks.direct-s3', [
            'driver' => 's3', 'visibility' => 'private', 'bucket' => 'studio-private-bucket',
            'region' => 'us-east-1', 'endpoint' => 'https://storage.example.invalid', 'use_path_style_endpoint' => true,
            'root' => 'adapter-root/scoped', 'key' => 'test-key', 'secret' => 'test-secret',
            'handler' => $this->storage, 'retries' => 0,
        ]);
        config()->set('media-studio.storage.disk', 'direct-s3');
        config()->set('media-studio.upload.sessions.enabled', true);
        config()->set('media-studio.upload.sessions.multipart', ['enabled' => true, 'threshold_bytes' => 1, 'part_bytes' => 5242880, 'url_seconds' => 60]);
    }

    private function createSession(string $workspace, int $bytes, bool $direct = true): string
    {
        return $this->postJson($this->url($workspace, 'upload-sessions'), [
            'name' => 'document.txt', 'size' => $bytes, 'key' => 'integration-test-key-0001', 'direct' => $direct,
        ])->assertCreated()->assertJsonPath('transport', $direct ? 's3-multipart' : 'local')->json('id');
    }

    private function endpoint(string $workspace, string $id, string $suffix = ''): string
    {
        return $this->url($workspace, 'upload-sessions/'.$id.$suffix);
    }

    private function startStorage(string $id): void
    {
        $this->storage->append(function (CommandInterface $command, RequestInterface $request) use ($id): Result {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('CreateMultipartUpload', $command->getName());
            $this->assertSame($id, $request->getHeaderLine('x-amz-meta-flex-upload-session'));
            $this->assertSame('', $request->getHeaderLine('x-amz-acl'));

            return new Result(['UploadId' => 'provider-upload-id']);
        });
    }

    private function reserve(string $workspace, string $id, int $index, string $bytes): array
    {
        return $this->postJson($this->endpoint($workspace, $id, '/parts/'.$index.'/sign'), [
            'sha256' => hash('sha256', $bytes),
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    private function receipt(string $workspace, string $id, int $index, string $etag): void
    {
        $this->postJson($this->endpoint($workspace, $id, '/parts/'.$index.'/receipt'), ['etag' => $etag])->assertOk();
    }

    private function missing(CommandInterface $command, string $code): S3Exception
    {
        return new S3Exception('Missing fixture resource.', $command, ['code' => $code, 'response' => new Response(404)]);
    }

    public function test_signed_parts_publish_one_verified_private_original_and_resume_out_of_order(): void
    {
        $workspace = $this->workspace();
        $first = str_repeat('First part text. ', 327680);
        $first = substr($first, 0, 5242880);
        $last = 'Last part text.';
        $content = $first.$last;
        $id = $this->createSession($workspace, strlen($content));
        $this->getJson($this->url($workspace, 'upload-policy'))->assertOk()->assertJsonPath('multipartThresholdBytes', 1);
        $this->startStorage($id);

        $signed = $this->reserve($workspace, $id, 1, $last);
        $uri = parse_url($signed['url']);
        parse_str($uri['query'], $query);
        $this->assertSame('storage.example.invalid', $uri['host']);
        $this->assertStringStartsWith('/studio-private-bucket/adapter-root/scoped/flex-media-studio/'.$workspace.'/originals/', $uri['path']);
        $this->assertSame('2', $query['partNumber']);
        $this->assertSame('provider-upload-id', $query['uploadId']);
        $this->assertSame('60', $query['X-Amz-Expires']);
        $this->assertSame('content-length;host;x-amz-checksum-sha256', $query['X-Amz-SignedHeaders']);
        $this->assertSame(base64_encode(hash('sha256', $last, true)), $signed['headers']['x-amz-checksum-sha256']);
        $this->assertStringNotContainsString('test-secret', $signed['url']);

        $this->receipt($workspace, $id, 1, '"last-etag"');
        $this->receipt($workspace, $id, 1, '"last-etag"');
        $this->getJson($this->endpoint($workspace, $id))->assertOk()->assertJsonPath('acceptedParts', [1]);
        $this->postJson($this->endpoint($workspace, $id, '/complete'))->assertConflict();
        $this->reserve($workspace, $id, 0, $first);
        $this->receipt($workspace, $id, 0, '"first-etag"');
        $this->assertDatabaseCount('fms_asset_versions', 0);

        $parts = [
            ['PartNumber' => 1, 'Size' => strlen($first), 'ETag' => '"first-etag"', 'ChecksumSHA256' => base64_encode(hash('sha256', $first, true))],
            ['PartNumber' => 2, 'Size' => strlen($last), 'ETag' => '"last-etag"', 'ChecksumSHA256' => base64_encode(hash('sha256', $last, true))],
        ];
        $this->storage->append(
            function (CommandInterface $command) use ($parts): Result {
                $this->assertSame('ListParts', $command->getName());

                return new Result(['Parts' => $parts]);
            },
            function (CommandInterface $command, RequestInterface $request): Result {
                $this->assertSame('CompleteMultipartUpload', $command->getName());
                $this->assertSame('*', $request->getHeaderLine('If-None-Match'));
                $this->assertSame([1, 2], array_column($command['MultipartUpload']['Parts'], 'PartNumber'));
                $this->assertSame(['"first-etag"', '"last-etag"'], array_column($command['MultipartUpload']['Parts'], 'ETag'));

                return new Result;
            },
            function (CommandInterface $command) use ($id, $content): Result {
                $this->assertSame('GetObject', $command->getName());
                $this->assertTrue($command['@http']['stream']);
                $this->assertSame(0, DB::transactionLevel());
                $this->assertDatabaseCount('fms_asset_versions', 0);

                return new Result(['ContentLength' => strlen($content), 'Metadata' => ['flex-upload-session' => $id], 'Body' => Utils::streamFor($content)]);
            },
        );

        $entry = $this->postJson($this->endpoint($workspace, $id, '/complete'))->assertOk()->json('id');
        $this->postJson($this->endpoint($workspace, $id, '/complete'))->assertOk()->assertJsonPath('id', $entry);
        $this->assertDatabaseCount('fms_asset_versions', 1);
        $this->assertDatabaseHas('fms_asset_versions', [
            'disk' => 'direct-s3', 'sha256' => hash('sha256', $content), 'size_bytes' => strlen($content), 'mime_type' => 'text/plain',
        ]);
        $this->assertDatabaseHas('fms_upload_sessions', ['public_id' => $id, 'state' => 'completed']);
    }

    public function test_server_rejects_mismatched_receipts_and_recovers_completion_when_provider_id_is_gone(): void
    {
        $workspace = $this->workspace();
        $bytes = 'Actual upload content.';
        $id = $this->createSession($workspace, strlen($bytes));
        $this->startStorage($id);
        $this->reserve($workspace, $id, 0, $bytes);
        $this->receipt($workspace, $id, 0, '"claimed"');
        $this->storage->append(new Result(['Parts' => [[
            'PartNumber' => 1, 'Size' => strlen($bytes), 'ETag' => '"actual"', 'ChecksumSHA256' => base64_encode(hash('sha256', $bytes, true)),
        ]]]));
        $this->postJson($this->endpoint($workspace, $id, '/complete'))->assertConflict();
        $this->assertDatabaseCount('fms_asset_versions', 0);

        $this->receipt($workspace, $id, 0, '"actual"');
        DB::table('fms_upload_sessions')->where('public_id', $id)->update(['state' => 'finalizing', 'lease_expires_at' => now()->subMinute()]);
        $this->storage->append(
            fn (CommandInterface $command) => $this->missing($command, 'NoSuchUpload'),
            new Result(['ContentLength' => strlen($bytes), 'Metadata' => ['flex-upload-session' => $id], 'Body' => Utils::streamFor(str_repeat('x', strlen($bytes)))]),
        );
        $this->postJson($this->endpoint($workspace, $id, '/complete'))->assertUnprocessable();
        $this->assertDatabaseCount('fms_asset_versions', 0);

        $this->storage->append(
            fn (CommandInterface $command) => $this->missing($command, 'NoSuchUpload'),
            new Result(['ContentLength' => strlen($bytes), 'Metadata' => ['flex-upload-session' => $id], 'Body' => Utils::streamFor($bytes)]),
        );
        $this->postJson($this->endpoint($workspace, $id, '/complete'))->assertOk();
        $this->assertDatabaseCount('fms_asset_versions', 1);
    }

    public function test_cross_workspace_and_revoked_actor_cannot_sign_or_publish(): void
    {
        $workspace = $this->workspace();
        $other = $this->workspace('Other space');
        $id = $this->createSession($workspace, 3);
        $sha = hash('sha256', 'abc');
        $this->postJson($this->endpoint($other, $id, '/parts/0/sign'), ['sha256' => $sha])->assertNotFound();
        $this->postJson($this->endpoint($workspace, $id, '/parts/1/sign'), ['sha256' => $sha])->assertConflict();
        $this->postJson($this->endpoint($workspace, $id, '/parts/0/sign'), ['sha256' => 'invalid'])->assertUnprocessable();
        $this->assertNull($this->storage->getLastCommand());
        $this->startStorage($id);
        $this->reserve($workspace, $id, 0, 'abc');
        $this->postJson($this->endpoint($workspace, $id, '/parts/0/sign'), ['sha256' => hash('sha256', 'xyz')])->assertConflict();

        $this->actingAs(new GenericUser(['id' => 'other-user']));
        $this->postJson($this->endpoint($workspace, $id, '/parts/0/receipt'), ['etag' => '"opaque"'])->assertNotFound();
        $this->actingAs(new GenericUser(['id' => 'member-uuid-001']));
        $this->receipt($workspace, $id, 0, '"opaque"');
        DB::table('fms_memberships')->update(['role' => 'viewer']);
        $this->postJson($this->endpoint($workspace, $id, '/complete'))->assertForbidden();
        $this->assertDatabaseCount('fms_asset_versions', 0);
    }

    public function test_cancel_and_expiry_clean_only_the_session_owned_storage_version(): void
    {
        $workspace = $this->workspace();
        $id = $this->createSession($workspace, 3);
        $this->startStorage($id);
        $this->reserve($workspace, $id, 0, 'abc');
        $this->storage->append(
            function (CommandInterface $command): Result {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame('AbortMultipartUpload', $command->getName());
                $this->assertSame('provider-upload-id', $command['UploadId']);

                return new Result;
            },
            fn (CommandInterface $command) => $this->missing($command, 'NotFound'),
        );
        $this->deleteJson($this->endpoint($workspace, $id))->assertOk();
        $this->deleteJson($this->endpoint($workspace, $id))->assertOk();
        $this->assertDatabaseHas('fms_upload_sessions', ['public_id' => $id, 'state' => 'cancelled']);

        $workspace = $this->workspace('Cleanup');
        $id = $this->createSession($workspace, 3);
        $this->startStorage($id);
        $this->reserve($workspace, $id, 0, 'abc');
        DB::table('fms_upload_sessions')->where('public_id', $id)->update(['expires_at' => now()->subMinute()]);
        $this->storage->append(
            fn (CommandInterface $command) => $this->missing($command, 'NoSuchUpload'),
            new Result(['Metadata' => ['flex-upload-session' => $id], 'VersionId' => 'staged-version']),
            function (CommandInterface $command): Result {
                $this->assertSame('DeleteObject', $command->getName());
                $this->assertSame('staged-version', $command['VersionId']);

                return new Result;
            },
        );
        $this->artisan('media-studio:uploads:prune')->assertSuccessful();
        $this->getJson($this->endpoint($workspace, $id))->assertGone();
        $this->assertDatabaseHas('fms_upload_sessions', ['public_id' => $id, 'state' => 'expired']);
        $this->assertDatabaseCount('fms_upload_parts', 0);
    }

    public function test_older_clients_keep_local_sessions_on_a_multipart_enabled_disk(): void
    {
        $workspace = $this->workspace();
        $id = $this->createSession($workspace, 3, direct: false);
        $this->postJson($this->endpoint($workspace, $id, '/parts/0/sign'), ['sha256' => hash('sha256', 'abc')])->assertConflict();
        $this->assertNull($this->storage->getLastCommand());
    }

    public function test_cancelled_remote_bytes_hold_the_reservation_until_provider_cleanup_succeeds(): void
    {
        config()->set('media-studio.upload.sessions.max_reserved_bytes', 4);
        $workspace = $this->workspace();
        $id = $this->createSession($workspace, 3);
        $this->startStorage($id);
        $this->reserve($workspace, $id, 0, 'abc');
        $this->storage->append(fn (CommandInterface $command) => new S3Exception('Access denied by fixture.', $command, ['code' => 'AccessDenied', 'response' => new Response(403)]));
        $this->deleteJson($this->endpoint($workspace, $id))->assertOk();
        $this->assertDatabaseHas('fms_upload_sessions', ['public_id' => $id, 'state' => 'cancelled', 'cleaned_at' => null]);

        $next = ['name' => 'another.txt', 'size' => 3, 'key' => 'integration-test-key-0002', 'direct' => true];
        $this->postJson($this->url($workspace, 'upload-sessions'), $next)->assertTooManyRequests();
        $this->storage->append(new Result, fn (CommandInterface $command) => $this->missing($command, 'NotFound'));
        $this->artisan('media-studio:uploads:prune')->assertSuccessful();
        $this->postJson($this->url($workspace, 'upload-sessions'), $next)->assertCreated();
    }
}
