<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Aws\Api\DateTimeResult;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Delivery\Delivery;
use UseFlex\MediaStudio\Delivery\DeliveryResource;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Sources\DiskIndexer;

final class DiskS3SourceTest extends TestCase
{
    public function test_private_s3_delivery_presigns_without_reading_or_copying_the_object(): void
    {
        config()->set('filesystems.disks.delivery-s3', [
            'driver' => 's3', 'bucket' => 'private-source', 'region' => 'eu-north-1', 'root' => 'tenant-root',
            'key' => 'fixture', 'secret' => 'fixture', 'endpoint' => 'https://storage.example.invalid', 'use_path_style_endpoint' => true,
            'handler' => function () {
                $this->fail('Signing must not perform a storage request.');
            },
        ]);
        config()->set('media-studio.delivery', ['enabled' => true, 'roles' => ['original' => 'private'], 'profiles' => ['private' => ['driver' => 's3', 'revocation' => 'expiry', 'allowed_hosts' => ['storage.example.invalid']]]]);
        $resource = new DeliveryResource('delivery-s3', 'a b.png', 'image/png', 'Zdjęcie.png', 'workspace', 'version', 'original', download: true);
        $response = app(Delivery::class)->redirect($resource);
        $this->assertSame(307, $response->getStatusCode());
        $url = $response->getTargetUrl();
        $this->assertStringContainsString('/private-source/tenant-root/a%20b.png', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('X-Amz-Signature', $query);
        $this->assertStringContainsString('attachment;', $query['response-content-disposition']);
        $this->assertSame('private, no-store', $query['response-cache-control']);
    }

    public function test_scheduled_s3_discovery_is_bounded_and_never_uses_listing_absence_as_deletion(): void
    {
        $commands = [];
        $empty = false;
        $denied = false;
        config()->set('filesystems.disks.sync-s3', [
            'driver' => 's3', 'bucket' => 'private-source', 'region' => 'eu-north-1',
            'root' => 'tenant-root', 'key' => 'fixture', 'secret' => 'fixture',
            'endpoint' => 'https://storage.example.invalid', 'use_path_style_endpoint' => true,
            'handler' => function (CommandInterface $command) use (&$commands, &$empty, &$denied): Result {
                $commands[] = $command->getName();
                if ($command->getName() === 'ListObjectsV2') {
                    $this->assertSame(1, $command['MaxKeys']);
                    $this->assertSame('tenant-root/archive/', $command['Prefix']);

                    return new Result(['IsTruncated' => false, 'Contents' => $empty ? [] : [['Key' => 'tenant-root/archive/document.txt']]]);
                }
                $this->assertSame('HeadObject', $command->getName());
                if ($denied) {
                    throw new AwsException('Denied', $command, ['code' => 'AccessDenied', 'response' => new Response(403)]);
                }

                return new Result(['ContentLength' => 12, 'ContentType' => 'text/plain', 'LastModified' => new DateTimeResult('2026-11-01T12:00:00Z')]);
            },
        ]);
        config()->set('media-studio.sources.mounts.private', ['disk' => 'sync-s3', 'prefix' => 'archive', 'authorize' => fn () => true]);
        $workspace = $this->workspace();
        config()->set('media-studio.sources.sync.targets.private', ['kind' => 'disk', 'workspace' => $workspace, 'mount' => 'private', 'principal_type' => 'user', 'principal_id' => 'member-uuid-001', 'interval_seconds' => 1]);
        $run = fn () => Artisan::call('media-studio:sources:sync', ['target' => 'private', '--limit' => 1]);
        $this->assertSame(0, $run());
        $this->assertSame(1, DB::table('fms_asset_versions')->count());
        $empty = true;
        $this->travel(2)->seconds();
        $this->assertSame(0, $run());
        $this->assertSame(0, DB::table('fms_disk_sources')->sum('missing_checks'));
        $this->assertSame(1, DB::table('fms_asset_versions')->count());
        $this->assertSame(['ListObjectsV2', 'HeadObject', 'HeadObject', 'ListObjectsV2'], $commands);
        $denied = true;
        $this->travel(2)->seconds();
        $this->assertSame(1, $run());
        $this->assertSame(0, DB::table('fms_disk_sources')->sum('missing_checks'));
        $this->assertNotNull(DB::table('fms_source_syncs')->value('failed_at'));
    }

    public function test_prefixed_private_s3_inventory_and_import_do_not_copy_or_delete_objects(): void
    {
        $commands = [];
        config()->set('filesystems.disks.source-s3', [
            'driver' => 's3', 'visibility' => 'private', 'bucket' => 'private-source',
            'region' => 'eu-north-1', 'root' => 'tenant-root', 'key' => 'fixture', 'secret' => 'fixture',
            'endpoint' => 'https://storage.example.invalid', 'use_path_style_endpoint' => true,
            'handler' => function (CommandInterface $command) use (&$commands): Result {
                $commands[] = $command->getName();
                if ($command->getName() === 'ListObjectsV2') {
                    $this->assertSame('tenant-root/archive/', $command['Prefix']);

                    return new Result(['IsTruncated' => false, 'Contents' => [['Key' => 'tenant-root/archive/document.txt', 'Size' => 12, 'LastModified' => new DateTimeResult('2026-11-01T12:00:00Z')]]]);
                }
                $this->assertSame('HeadObject', $command->getName());
                $this->assertSame('tenant-root/archive/document.txt', $command['Key']);

                return new Result(['ContentLength' => 12, 'ContentType' => 'text/plain', 'LastModified' => new DateTimeResult('2026-11-01T12:00:00Z')]);
            },
        ]);
        config()->set('media-studio.sources.mounts.private', ['disk' => 'source-s3', 'prefix' => 'archive', 'authorize' => fn () => true]);
        $workspace = $this->workspace();
        $path = sys_get_temp_dir().'/fms-s3-inventory-'.bin2hex(random_bytes(8)).'.jsonl';
        try {
            $this->assertSame(0, Artisan::call('media-studio:sources:inventory', ['workspace' => $workspace, 'mount' => 'private', 'principal-type' => 'user', 'principal-id' => 'member-uuid-001', 'output' => $path]));
            $this->assertSame(['path' => 'archive/document.txt'], json_decode(trim(file_get_contents($path)), true));
            app(DiskIndexer::class)->importPage(new Principal('user', 'member-uuid-001'), $workspace, 'private', ['archive/document.txt'], true);
            $version = DB::table('fms_asset_versions')->first();
            $this->assertSame('source-s3', $version->disk);
            $this->assertSame('archive/document.txt', $version->object_key);
            $this->assertSame(12, $version->size_bytes);
            $this->assertSame(['ListObjectsV2', 'HeadObject'], $commands);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_inventory_resumes_from_committed_page_and_discards_uncommitted_tail(): void
    {
        $tokens = [];
        $allowed = true;
        $revokeAfterPage = false;
        config()->set('filesystems.disks.resume-s3', [
            'driver' => 's3', 'bucket' => 'source', 'region' => 'eu-north-1', 'root' => 'tenant', 'key' => 'fixture', 'secret' => 'fixture',
            'handler' => function (CommandInterface $command) use (&$tokens, &$allowed, &$revokeAfterPage): Result {
                $this->assertSame('ListObjectsV2', $command->getName());
                $this->assertSame('tenant/archive/', $command['Prefix']);
                $tokens[] = $command['ContinuationToken'];
                if ($revokeAfterPage) {
                    $allowed = false;
                }

                return $command['ContinuationToken'] === null
                    ? new Result(['IsTruncated' => true, 'NextContinuationToken' => 'page-two', 'Contents' => [['Key' => 'tenant/archive/one.txt']]])
                    : new Result(['IsTruncated' => false, 'Contents' => [['Key' => 'tenant/archive/two.txt']]]);
            },
        ]);
        config()->set('media-studio.sources.mounts.resume', ['disk' => 'resume-s3', 'prefix' => 'archive', 'authorize' => function () use (&$allowed): bool {
            return $allowed;
        }]);
        $workspace = $this->workspace();
        $output = sys_get_temp_dir().'/fms-resume-'.bin2hex(random_bytes(8)).'.jsonl';
        $args = ['workspace' => $workspace, 'mount' => 'resume', 'principal-type' => 'user', 'principal-id' => 'member-uuid-001', 'output' => $output, '--pages' => 1];
        try {
            $this->assertSame(0, Artisan::call('media-studio:sources:inventory', $args));
            $this->assertFileDoesNotExist($output);
            $this->assertFileExists($output.'.checkpoint');
            $this->assertSame(0600, fileperms($output.'.partial') & 0777);
            config()->set('media-studio.sources.mounts.resume.revision', 2);
            try {
                Artisan::call('media-studio:sources:inventory', $args + ['--resume' => true]);
                $this->fail('A changed source must not resume an earlier inventory.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertSame([null], $tokens);
            $this->assertFileDoesNotExist($output);
            config()->set('media-studio.sources.mounts.resume.revision', 1);
            $lock = fopen($output.'.lock', 'r+b');
            flock($lock, LOCK_EX);
            try {
                Artisan::call('media-studio:sources:inventory', $args + ['--resume' => true]);
                $this->fail('A concurrent scanner must not acquire the same inventory.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $revokeAfterPage = true;
            try {
                Artisan::call('media-studio:sources:inventory', $args + ['--resume' => true]);
                $this->fail('Revoked access must prevent publishing a listed page.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
            $this->assertFileDoesNotExist($output);
            $allowed = true;
            $revokeAfterPage = false;
            file_put_contents($output.'.partial', 'interrupted-tail', FILE_APPEND);
            $this->assertSame(0, Artisan::call('media-studio:sources:inventory', $args + ['--resume' => true]));
            $this->assertSame([null, 'page-two', 'page-two'], $tokens);
            $this->assertSame([['path' => 'archive/one.txt'], ['path' => 'archive/two.txt']], array_map(fn ($line) => json_decode($line, true), file($output, FILE_IGNORE_NEW_LINES)));
            $this->assertFileDoesNotExist($output.'.checkpoint');
            $this->assertFileDoesNotExist($output.'.partial');
        } finally {
            foreach ([$output, $output.'.partial', $output.'.checkpoint', $output.'.lock'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
