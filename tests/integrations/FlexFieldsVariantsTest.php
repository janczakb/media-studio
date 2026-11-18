<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Integrations;

use Bjanczak\FilamentFlexFields\Support\FileUpload\Media\Storage\UploadManifest;
use Bjanczak\FilamentFlexFields\Support\FileUpload\Media\Storage\UploadManifestRepository;
use Bjanczak\FilamentFlexFields\Support\Media\Contracts\ImageEncoder;
use Bjanczak\FilamentFlexFields\Support\Media\Contracts\MediaAccessPolicy;
use Bjanczak\FilamentFlexFields\Support\Media\Data\MediaDescriptor;
use Bjanczak\FilamentFlexFields\Support\Media\Data\MediaFailure;
use Bjanczak\FilamentFlexFields\Support\Media\Data\MediaReceipt;
use Bjanczak\FilamentFlexFields\Support\Media\Data\MediaWrite;
use Bjanczak\FilamentFlexFields\Support\Media\Images\GdImageEncoder;
use Bjanczak\FilamentFlexFields\Support\Media\Images\ImageConversion;
use Bjanczak\FilamentFlexFields\Support\Media\Images\ImageFormat;
use Bjanczak\FilamentFlexFields\Support\Media\Images\Queue\ConversionJournal;
use Bjanczak\FilamentFlexFields\Support\Media\Images\Queue\ConversionQueueOptions;
use Bjanczak\FilamentFlexFields\Support\Media\Images\Queue\GenerateImageConversions;
use Bjanczak\FilamentFlexFields\Support\Media\Images\Queue\QueuedImageConversions;
use Bjanczak\FilamentFlexFields\Support\Media\Runtime\MediaExtensionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Integrations\FlexFields\UploadManifestVariants;
use UseFlex\MediaStudio\Renditions\Renditions;
use UseFlex\MediaStudio\Tests\TestCase;

final class FlexFieldsVariantsTest extends TestCase
{
    protected function setUp(): void
    {
        $source = getenv('FLEX_FIELDS_SOURCE');
        if (! class_exists(UploadManifestRepository::class, false) && is_string($source) && is_file($source.'/src/Support/FileUpload/Media/Storage/UploadManifestRepository.php')) {
            $loader = require __DIR__.'/vendor/autoload.php';
            $loader->addPsr4('Bjanczak\\FilamentFlexFields\\', $source.'/src/');
        }
        if (! class_exists(UploadManifestRepository::class)) {
            $this->markTestSkipped('Set FLEX_FIELDS_SOURCE to an installed FLEX Fields checkout to verify actual encrypted upload manifests.');
        }
        parent::setUp();
        config()->set('filament-flex-fields.media_capture.uploads.manifest_disk', 'flex-receipts');
        Storage::fake('flex-receipts');
        Storage::fake('flex-private');
        Storage::fake('flex-derivatives');
        Storage::fake('flex-journals');
    }

    public function test_authenticated_flex_receipts_reuse_private_variants_without_rewriting_them(): void
    {
        $original = $this->receipt('source.png', 'flex-private', $this->png(48, 32));
        $card = $this->receipt('card.png', 'flex-derivatives', $this->png(24, 16));
        (new UploadManifestRepository(app('encrypter')))->write(new UploadManifest($original, ['card' => $card]));
        $version = $this->version($original);
        $calls = [];
        $provider = new UploadManifestVariants(static function (object $workspace, object $entry, object $version, Principal $principal, MediaReceipt $receipt, string $name) use (&$calls): bool {
            $calls[] = $name;

            return $principal->id === 'owner' && $workspace->id === 17 && $entry->id === 23;
        });
        $originalFiles = Storage::disk('flex-private')->allFiles();
        $derivativeFiles = Storage::disk('flex-derivatives')->allFiles();
        $variants = $provider->variants((object) ['id' => 17], (object) ['id' => 23], $version, new Principal('user', 'owner'));

        $this->assertCount(1, $variants);
        $this->assertSame(['original', 'card'], $calls);
        $this->assertSame('card', $variants[0]->name);
        $this->assertSame('flex-derivatives', $variants[0]->disk);
        $this->assertSame('card.png', $variants[0]->objectKey);
        $this->assertSame('image/png', $variants[0]->mimeType);
        $this->assertSame(24, $variants[0]->width);
        $this->assertSame(16, $variants[0]->height);
        $this->assertSame($card->file->sha256, $variants[0]->sha256);
        $this->assertSame($originalFiles, Storage::disk('flex-private')->allFiles());
        $this->assertSame($derivativeFiles, Storage::disk('flex-derivatives')->allFiles());
        $this->assertSame($card->file->bytes, strlen(Storage::disk('flex-derivatives')->get('card.png')));

        Storage::disk('flex-receipts')->delete(Storage::disk('flex-receipts')->allFiles());
        $this->assertSame([], $provider->variants((object) ['id' => 17], (object) ['id' => 23], $version, new Principal('user', 'owner')));
    }

    public function test_source_hash_and_owner_revocation_are_rechecked_before_exposing_variants(): void
    {
        $original = $this->receipt('source.png', 'flex-private', $this->png(48, 32));
        $card = $this->receipt('card.png', 'flex-derivatives', $this->png(24, 16));
        (new UploadManifestRepository(app('encrypter')))->write(new UploadManifest($original, ['card' => $card]));
        $provider = new UploadManifestVariants(static fn (object $workspace, object $entry, object $version, Principal $principal): bool => $principal->id === 'owner');
        foreach ([[$this->version($original), 'revoked'], [(object) [...(array) $this->version($original), 'sha256' => hash('sha256', 'changed')], 'owner']] as [$version, $actor]) {
            try {
                $provider->variants((object) [], (object) [], $version, new Principal('user', $actor));
                $this->fail('Unauthorized or outdated receipts were exposed.');
            } catch (HttpException $error) {
                $this->assertSame(404, $error->getStatusCode());
            }
        }
    }

    public function test_an_optimized_primary_is_matched_without_reimporting_it_as_a_conversion(): void
    {
        $original = $this->receipt('source.png', 'flex-private', $this->png(48, 32));
        $optimized = $this->receipt('optimized.png', 'flex-derivatives', $this->png(40, 28));
        $card = $this->receipt('card.png', 'flex-derivatives', $this->png(24, 16));
        (new UploadManifestRepository(app('encrypter')))->write(new UploadManifest($original, ['optimized' => $optimized, 'card' => $card], 'optimized'));
        $provider = new UploadManifestVariants(static fn (): bool => true);
        $variants = $provider->variants((object) [], (object) [], $this->version($optimized), new Principal('user', 'owner'));

        $this->assertCount(1, $variants);
        $this->assertSame('card', $variants[0]->name);
        $this->assertSame([], Storage::disk('studio-test')->allFiles());
    }

    public function test_existing_receipts_register_as_provider_owned_variants_and_recheck_live_access(): void
    {
        $workspaceId = $this->workspace();
        $workspace = DB::table('fms_workspaces')->where('public_id', $workspaceId)->first();
        $original = $this->receipt('source.png', 'flex-private', $this->png(48, 32));
        $card = $this->receipt('card.png', 'flex-derivatives', $this->png(24, 16));
        (new UploadManifestRepository(app('encrypter')))->write(new UploadManifest($original, ['card' => $card]));
        $entryId = (string) Str::ulid();
        $versionId = (string) Str::ulid();
        $id = DB::table('fms_entries')->insertGetId(['workspace_id' => $workspace->id, 'public_id' => $entryId,
            'kind' => 'file', 'sort_group' => 1, 'name' => 'source.png', 'normalized_name' => 'source.png',
            'media_type' => 'image', 'mime_type' => 'image/png', 'size_bytes' => $original->file->bytes,
            'current_version_id' => $versionId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fms_asset_versions')->insert(['id' => $versionId, 'entry_id' => $id, 'disk' => $original->disk,
            'object_key' => $original->path, 'sha256' => $original->file->sha256,
            'size_bytes' => $original->file->bytes, 'mime_type' => $original->file->mimeType, 'created_at' => now()]);
        config()->set('filesystems.disks.flex-derivatives', ['driver' => 'local', 'root' => Storage::disk('flex-derivatives')->path('')]);
        config()->set('media-studio.processing.external_providers.flex', UploadManifestVariants::class);
        $allowed = true;
        app()->bind(UploadManifestVariants::class, static function () use (&$allowed): UploadManifestVariants {
            return new UploadManifestVariants(static function () use (&$allowed): bool {
                return $allowed;
            });
        });
        $principal = new Principal('user', 'member-uuid-001');
        $renditions = app(Renditions::class);
        $renditions->syncExternal($principal, $workspaceId, $entryId, 'flex', ['thumbnail' => 'card', 'preview' => 'card']);
        $entry = DB::table('fms_entries')->find($id);
        $rendition = DB::table('fms_renditions')->where('entry_id', $id)->first();

        $this->assertSame('ready', $entry->preview_state);
        $this->assertSame('card.png', $entry->preview_key);
        $this->assertSame('flex-derivatives', $entry->preview_disk);
        $this->assertSame('image/png', $entry->preview_mime_type);
        $this->assertSame(0, $rendition->owned);
        $this->assertSame('flex', $rendition->provider);
        $this->assertSame([], Storage::disk('studio-test')->allFiles());
        $renditions->assertExternal($workspace, $entry, $rendition, $principal);
        $allowed = false;
        $this->expectException(HttpException::class);
        $renditions->assertExternal($workspace, $entry, $rendition, $principal);
    }

    public function test_queued_variants_wait_for_native_ready_state_and_do_not_dispatch_twice(): void
    {
        [$queue, $journal, $policy] = $this->queueRuntime();
        $original = $this->receipt('source.png', 'flex-private', $this->png(48, 32));
        $authority = new MediaWrite(disk: 'flex-private', directory: 'queued');
        $journalId = $queue->prepare($original, $authority, [
            new ImageConversion('card', width: 24, format: ImageFormat::Png, disk: 'flex-derivatives'),
        ], new ConversionQueueOptions(connection: 'flex-native'));
        (new UploadManifestRepository(app('encrypter')))->write(new UploadManifest($original, context: ['queued_images' => $journalId]));
        $provider = new UploadManifestVariants(static fn (): bool => true, static fn (): MediaWrite => $authority);
        $read = fn (): iterable => $provider->variants((object) [], (object) [], $this->version($original), new Principal('user', 'owner'));

        $this->assertSame([], $read());
        $queue->dispatch($journalId);
        $this->assertSame([], $read());
        Queue::assertPushed(GenerateImageConversions::class, 1);
        $queue->process($journalId);
        $this->assertSame('ready', $journal->read($journalId)['status']);
        $paths = Storage::disk('flex-derivatives')->allFiles();
        $variants = $read();
        $this->assertCount(1, $variants);
        $this->assertSame('card', $variants[0]->name);
        $this->assertSame('flex-derivatives', $variants[0]->disk);
        $this->assertSame(24, $variants[0]->width);
        $this->assertSame(16, $variants[0]->height);
        $this->assertSame('image/png', getimagesizefromstring(Storage::disk('flex-derivatives')->get($variants[0]->objectKey))['mime']);
        $this->assertSame($variants[0]->sha256, hash('sha256', Storage::disk('flex-derivatives')->get($variants[0]->objectKey)));
        $this->assertSame($paths, Storage::disk('flex-derivatives')->allFiles());
        $read();
        Queue::assertPushed(GenerateImageConversions::class, 1);
        $this->assertSame([], Storage::disk('studio-test')->allFiles());
        foreach (['processing', 'retrying', 'failed', 'cancelled'] as $state) {
            $journal->update($journalId, static fn (array $batch): array => [...$batch, 'status' => $state]);
            $this->assertSame([], $read());
        }
        $journal->update($journalId, static fn (array $batch): array => [...$batch, 'status' => 'ready']);
        $policy->allowed = false;
        try {
            $read();
            $this->fail('The native Media OS access policy was bypassed.');
        } catch (MediaFailure $failure) {
            $this->assertSame('denied', $failure->reason);
        }
    }

    public function test_queued_manifest_cannot_reuse_a_different_originals_journal(): void
    {
        [$queue] = $this->queueRuntime();
        $source = $this->receipt('source.png', 'flex-private', $this->png(48, 32));
        $different = $this->receipt('different.png', 'flex-private', $this->png(40, 30));
        $authority = new MediaWrite(disk: 'flex-private');
        $journalId = $queue->prepare($source, $authority, [new ImageConversion('card', width: 24)], new ConversionQueueOptions(connection: 'flex-native'));
        (new UploadManifestRepository(app('encrypter')))->write(new UploadManifest($different, context: ['queued_images' => $journalId]));
        $provider = new UploadManifestVariants(static fn (): bool => true, static fn (): MediaWrite => $authority);

        $this->expectException(MediaFailure::class);
        $provider->variants((object) [], (object) [], $this->version($different), new Principal('user', 'owner'));
    }

    public function test_queued_manifests_require_an_explicit_owning_application_authority(): void
    {
        [$queue] = $this->queueRuntime();
        $source = $this->receipt('source.png', 'flex-private', $this->png(48, 32));
        $authority = new MediaWrite(disk: 'flex-private');
        $journalId = $queue->prepare($source, $authority, [new ImageConversion('card', width: 24)], new ConversionQueueOptions(connection: 'flex-native'));
        (new UploadManifestRepository(app('encrypter')))->write(new UploadManifest($source, context: ['queued_images' => $journalId]));
        $provider = new UploadManifestVariants(static fn (): bool => true);

        $this->expectException(\LogicException::class);
        $provider->variants((object) [], (object) [], $this->version($source), new Principal('user', 'owner'));
    }

    /** @return array{QueuedImageConversions, ConversionJournal, MediaAccessPolicy} */
    private function queueRuntime(): array
    {
        config()->set('filament-flex-fields.media_capture.images.queue.journal_disk', 'flex-journals');
        config()->set('queue.connections.flex-native', ['driver' => 'database', 'retry_after' => 120]);
        app()->bind(ImageEncoder::class, GdImageEncoder::class);
        $policy = new class implements MediaAccessPolicy
        {
            public bool $allowed = true;

            public function authorize(string $action, MediaWrite $intent, ?MediaReceipt $receipt): void
            {
                if (! $this->allowed) {
                    throw new MediaFailure('denied', 'Native source access was revoked.');
                }
            }
        };
        $extensions = new MediaExtensionState;
        $extensions->accessPolicies['source-owner'] = static fn (): MediaAccessPolicy => $policy;
        app()->instance(MediaExtensionState::class, $extensions);
        Queue::fake([GenerateImageConversions::class]);

        return [app(QueuedImageConversions::class), app(ConversionJournal::class), $policy];
    }

    private function version(MediaReceipt $receipt): object
    {
        return (object) ['disk' => $receipt->disk, 'object_key' => $receipt->path, 'sha256' => $receipt->file->sha256,
            'size_bytes' => $receipt->file->bytes, 'mime_type' => $receipt->file->mimeType];
    }

    private function receipt(string $path, string $disk, string $bytes): MediaReceipt
    {
        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private']);
        $image = getimagesizefromstring($bytes);

        return new MediaReceipt('disk', $path, $disk, $path, new MediaDescriptor(strlen($bytes), 'image/png', hash('sha256', $bytes), $path, $image[0], $image[1]), 'operation-'.$path);
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }
}
