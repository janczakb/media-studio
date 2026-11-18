<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use UseFlex\MediaCore\Images\GdImageRenderer;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\UploadAsset;
use UseFlex\MediaStudio\Processing\GenerateThumbnail;
use UseFlex\MediaStudio\Processing\ThumbnailProcessor;
use UseFlex\MediaStudio\Renditions\ExternalVariant;
use UseFlex\MediaStudio\Renditions\ExternalVariantProvider;
use UseFlex\MediaStudio\Renditions\Renditions;

final class NativeRenditionsTest extends TestCase
{
    private function upload(string $workspace): array
    {
        return $this->withHeader('Idempotency-Key', 'native-render-test-'.Str::random(12))
            ->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->image('source.png', 800, 400)])
            ->assertCreated()->json();
    }

    public function test_picker_thumbnail_projection_uses_only_the_requested_published_preset(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        config()->set('media-studio.processing.renditions', [
            'thumbnail-v1' => ['width' => 160, 'height' => 160, 'fit' => 'cover', 'format' => 'webp'],
            'picker-card' => ['width' => 96, 'height' => 96, 'fit' => 'cover', 'format' => 'webp'],
        ]);
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        $this->getJson($this->url($workspace).'?thumbnailPreset=picker-card')->assertOk()->assertJsonPath('items.0.thumbnailUrl', null);
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
        $expected = url($this->url($workspace, 'entries/'.$file['id'].'/versions/'.$file['versionId'].'/renditions/picker-card'));
        DB::enableQueryLog();
        $page = $this->getJson($this->url($workspace).'?thumbnailPreset=picker-card')->assertOk();
        $this->assertSame($expected, $page->json('items.0.thumbnailUrl'));
        $metadata = app(\UseFlex\MediaStudio\Library\MediaReferences::class)->resolve(
            Principal::fromUser(auth()->user()), $workspace, [['assetId' => $file['id'], 'versionId' => $file['versionId']]],
            presentation: true, thumbnailPreset: 'picker-card');
        $this->assertSame($expected, $metadata[0]['thumbnailUrl']);
        DB::disableQueryLog();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $cards = app(\UseFlex\MediaStudio\Library\ThumbnailProjection::class)->apply($workspace,
            array_fill(0, 120, ['id' => $file['id'], 'versionId' => $file['versionId']]), 'picker-card');
        $this->assertCount(120, $cards);
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $this->get($expected)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->postJson($this->url($workspace, 'entries/status'), ['ids' => [$file['id']], 'thumbnailPreset' => 'picker-card'])
            ->assertOk()->assertJsonPath('items.0.thumbnailUrl', $expected);
        $this->getJson($this->url($workspace).'?thumbnailPreset=missing-preset')->assertOk()->assertJsonPath('items.0.thumbnailUrl', null);
        $this->getJson($this->url($workspace).'?thumbnailPreset=../original')->assertUnprocessable();
        DB::table('fms_entries')->where('public_id', $file['id'])->update(['current_version_id' => (string) Str::ulid()]);
        $this->getJson($this->url($workspace).'?thumbnailPreset=picker-card')->assertOk()->assertJsonPath('items.0.thumbnailUrl', null);
    }

    public function test_one_job_generates_named_formats_from_an_immutable_recipe_and_exposes_private_roles(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        config()->set('media-studio.processing.renditions', [
            'thumbnail-v1' => ['width' => 160, 'height' => 160, 'fit' => 'cover', 'format' => 'webp'],
            'preview-v1' => ['width' => 600, 'height' => 600, 'format' => 'webp'],
            'padded' => ['width' => 300, 'height' => 300, 'fit' => 'pad', 'format' => 'webp', 'background' => '#ff0000'],
            'no-upscale' => ['format' => 'webp', 'width' => 1600, 'height' => 800],
        ]);
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        $this->assertSame('queued', $file['previewState']);
        $this->assertNull($file['previewUrl']);
        Bus::assertDispatchedTimes(GenerateThumbnail::class, 1);
        config()->set('media-studio.processing.renditions.thumbnail-v1.width', 99);
        config()->set('media-studio.processing.preview.preset', 'new-preview-v2');
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
        $rows = DB::table('fms_renditions')->get()->keyBy('preset');
        $this->assertCount(4, $rows);
        $this->assertSame([160, 160], [(int) $rows['thumbnail-v1']->width, (int) $rows['thumbnail-v1']->height]);
        $this->assertSame([600, 300], [(int) $rows['preview-v1']->width, (int) $rows['preview-v1']->height]);
        $this->assertSame([800, 400], [(int) $rows['no-upscale']->width, (int) $rows['no-upscale']->height]);
        $this->assertSame(hash_file('sha256', Storage::disk('studio-test')->path($rows['preview-v1']->object_key)), $rows['preview-v1']->sha256);
        $details = $this->getJson($this->url($workspace, 'entries/'.$file['id']))->assertOk()->assertJsonPath('previewState', 'ready')->json();
        $thumbnail = $this->get($details['thumbnailUrl'])->assertOk()->assertHeader('Content-Type', 'image/webp')->streamedContent();
        $this->assertSame('image/webp', getimagesizefromstring($thumbnail)['mime']);
        $preview = $this->get($details['previewUrl'])->assertOk()->assertHeader('Content-Type', 'image/webp')->streamedContent();
        $this->assertSame([600, 300], array_slice(getimagesizefromstring($preview), 0, 2));
        $padded = $this->get($this->url($workspace, 'entries/'.$file['id'].'/versions/'.$file['versionId'].'/renditions/padded'))
            ->assertOk()->streamedContent();
        $image = imagecreatefromstring($padded);
        $pixel = imagecolorat($image, 0, 0);
        $this->assertGreaterThanOrEqual(250, ($pixel >> 16) & 255);
        $this->assertLessThanOrEqual(5, ($pixel >> 8) & 255);
        $this->assertLessThanOrEqual(5, $pixel & 255);
        $this->assertSame([300, 300], [imagesx($image), imagesy($image)]);
        DB::enableQueryLog();
        $references = array_fill(0, 100, ['assetId' => $file['id'], 'versionId' => $file['versionId'], 'rendition' => 'padded']);
        $resolved = $this->postJson($this->url($workspace, 'references/resolve'), ['references' => $references])->assertOk()->assertJsonCount(100, 'items');
        $this->assertLessThanOrEqual(4, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertSame($padded, $this->get($resolved->json('items.0.url'))->assertOk()->streamedContent());
        $this->postJson($this->url($workspace, 'references/resolve'), ['references' => [['assetId' => $file['id'], 'rendition' => 'missing']]])->assertNotFound();
        $this->assertSame(5, count(Storage::disk('studio-test')->allFiles()));
        $this->assertSame(1, DB::table('fms_activity')->where('entry_public_id', $file['id'])->where('event', 'processed')->count());
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
        $this->assertSame(1, DB::table('fms_activity')->where('entry_public_id', $file['id'])->where('event', 'processed')->count());
        $this->assertSame(5, count(Storage::disk('studio-test')->allFiles()));
        DB::table('fms_entries')->where('public_id', $file['id'])->update(['current_version_id' => (string) Str::ulid()]);
        $this->get($details['previewUrl'])->assertNotFound();
    }

    public function test_regeneration_keeps_published_bytes_until_success_and_prunes_only_opted_in_variants(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        $processor = app(ThumbnailProcessor::class);
        Bus::dispatched(GenerateThumbnail::class)->last()->handle($processor);
        $entryUrl = $this->url($workspace, 'entries/'.$file['id']);
        $original = DB::table('fms_asset_versions')->where('id', $file['versionId'])->first();
        $disk = Storage::disk('studio-test');
        $originalBytes = $disk->get($original->object_key);
        $oldThumbnail = DB::table('fms_renditions')->where('preset', 'thumbnail-v1')->first();
        $thumbnailUrl = $this->getJson($entryUrl)->assertOk()->json('thumbnailUrl');
        $oldBytes = $this->get($thumbnailUrl)->assertOk()->streamedContent();

        config()->set('media-studio.processing.renditions.thumbnail-v1.width', 160);
        $replace = ['revision' => $file['revision'], 'replace' => true, 'retention' => 'prune'];
        $this->postJson($entryUrl.'/optimize', $replace)->assertOk()->assertJsonPath('thumbnailState', 'ready');
        $this->postJson($entryUrl.'/optimize', $replace)->assertOk();
        Bus::assertDispatchedTimes(GenerateThumbnail::class, 2);
        $this->assertSame($oldBytes, $this->get($thumbnailUrl)->assertOk()->streamedContent());

        config()->set('media-studio.processing.native.driver', 'unavailable');
        try {
            Bus::dispatched(GenerateThumbnail::class)->last()->handle($processor);
            $this->fail('The unavailable image driver must fail the refresh.');
        } catch (\RuntimeException $error) {
            $this->assertSame('encoder_unavailable', $error->getMessage());
        }
        $this->assertSame($oldBytes, $this->get($thumbnailUrl)->assertOk()->streamedContent());
        $this->assertDatabaseCount('fms_retired_renditions', 0);

        config()->set('media-studio.processing.native.driver', 'gd');
        $this->postJson($entryUrl.'/optimize', $replace)->assertOk();
        Bus::dispatched(GenerateThumbnail::class)->last()->handle($processor);
        $newBytes = $this->get($thumbnailUrl)->assertOk()->streamedContent();
        $this->assertSame(160, getimagesizefromstring($newBytes)[0]);
        $this->assertNotSame($oldBytes, $newBytes);
        $this->assertDatabaseCount('fms_retired_renditions', 2);
        $disk->assertExists($oldThumbnail->object_key);
        $this->artisan('media-studio:renditions:prune', ['workspace' => $workspace, '--apply' => true])->assertSuccessful();
        $disk->assertExists($oldThumbnail->object_key);
        $this->travel(2)->days();
        $this->artisan('media-studio:renditions:prune', ['workspace' => $workspace])->assertSuccessful();
        $disk->assertExists($oldThumbnail->object_key);
        $this->artisan('media-studio:renditions:prune', ['workspace' => $workspace, '--apply' => true])->assertSuccessful();
        $disk->assertMissing($oldThumbnail->object_key);
        $this->assertSame($originalBytes, $disk->get($original->object_key));
        $this->assertSame($newBytes, $this->get($thumbnailUrl)->assertOk()->streamedContent());

        $this->postJson($entryUrl.'/optimize', [...$replace, 'retention' => 'keep'])->assertOk();
        Bus::dispatched(GenerateThumbnail::class)->last()->handle($processor);
        $this->assertSame(2, DB::table('fms_retired_renditions')->whereNull('delete_after')->count());
    }

    public function test_laravel_uploads_keep_the_owner_model_recipe_when_studio_regenerates_them(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        $workspace = $this->workspace();
        $principal = new Principal('user', 'member-uuid-001');
        $resolved = app(WorkspaceAccess::class)->resolve($principal, $workspace, write: true);
        $post = new RenditionTestPost;
        config()->set('media-studio.processing.model_renditions.'.$post::class, [
            'thumbnail-v1' => ['format' => 'webp', 'width' => 220, 'height' => 220, 'quality' => 64],
        ]);
        $file = app(UploadAsset::class)->execute($resolved, $principal, null, UploadedFile::fake()->image('post.png', 800, 400),
            'model-owned-file-00001', owner: $post);
        Bus::dispatched(GenerateThumbnail::class)->last()->handle(app(ThumbnailProcessor::class));
        $row = DB::table('fms_renditions')->where('version_id', $file->current_version_id)->sole();
        $this->assertSame(220, (int) $row->width);
        $this->assertSame(64, json_decode($row->recipe, true)['quality']);

        config()->set('media-studio.processing.model_renditions.'.$post::class.'.thumbnail-v1.width', 150);
        $this->postJson($this->url($workspace, 'entries/'.$file->public_id.'/optimize'), [
            'revision' => $file->revision, 'replace' => true,
        ])->assertOk()->assertJsonPath('renditionsRefreshing', true);
        Bus::dispatched(GenerateThumbnail::class)->last()->handle(app(ThumbnailProcessor::class));
        $this->assertSame(150, (int) DB::table('fms_renditions')->where('version_id', $file->current_version_id)->sole()->width);
    }

    public function test_auto_uses_an_available_avif_encoder_without_skipping_other_presets(): void
    {
        if (! GdImageRenderer::supports('avif')
            && (! extension_loaded('imagick') || \Imagick::queryFormats('AVIF') === [])) {
            $this->markTestSkipped('No native AVIF encoder on this host.');
        }

        Bus::fake([GenerateThumbnail::class]);
        config()->set('media-studio.processing.renditions.thumbnail-v1.format', 'avif');
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        Bus::dispatched(GenerateThumbnail::class)->last()->handle(app(ThumbnailProcessor::class));

        $details = $this->getJson($this->url($workspace, 'entries/'.$file['id']))->assertOk()->json();
        $thumbnail = $this->get($details['thumbnailUrl'])->assertOk()->assertHeader('Content-Type', 'image/avif')->streamedContent();
        $this->assertSame('image/avif', getimagesizefromstring($thumbnail)['mime']);
        $this->get($details['previewUrl'])->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_refresh_rejects_stale_revisions_viewers_and_other_workspaces_before_queueing(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        $target = $this->url($workspace, 'entries/'.$file['id'].'/optimize');
        $refresh = ['revision' => $file['revision'], 'replace' => true, 'retention' => 'prune'];
        $this->postJson($target, [...$refresh, 'revision' => $file['revision'] + 1])->assertConflict();
        $this->postJson($target, [...$refresh, 'retention' => 'delete-original'])->assertUnprocessable();
        $other = $this->workspace('Another space');
        $this->postJson($this->url($other, 'entries/'.$file['id'].'/optimize'), $refresh)->assertNotFound();
        DB::table('fms_memberships')->update(['role' => 'viewer']);
        $this->postJson($target, $refresh)->assertForbidden();
        Bus::assertDispatchedTimes(GenerateThumbnail::class, 1);
        $this->assertDatabaseCount('fms_retired_renditions', 0);
        $this->assertSame(0, DB::table('fms_renditions')->whereNotNull('replacement_recipe')->count());
    }

    public function test_two_variants_share_a_source_read_and_an_expired_writer_cannot_publish(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        $base = Storage::disk('studio-test');
        $disk = new CountingRenditionDisk($base->getDriver(), $base->getAdapter(), $base->getConfig());
        Storage::set('studio-test', $disk);
        $job = Bus::dispatched(GenerateThumbnail::class)->first();
        $job->handle(app(ThumbnailProcessor::class));
        $this->assertSame(1, $disk->reads);
        $job->handle(app(ThumbnailProcessor::class));
        $this->assertSame(1, $disk->reads);

        $disk->expireOnWrite = true;
        DB::table('fms_renditions')->update(['state' => 'queued', 'attempts' => 0]);
        $job->handle(app(ThumbnailProcessor::class));
        $this->assertSame(2, DB::table('fms_renditions')->where('state', 'processing')->count());
        $this->assertCount(3, $disk->allFiles());
    }

    public function test_reconciliation_recovers_a_lost_dispatch_and_rechecks_the_worker_principal(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        DB::table('fms_renditions')->update(['last_dispatched_at' => null]);
        $this->artisan('media-studio:renditions:reconcile')->assertSuccessful();
        Bus::assertDispatchedTimes(GenerateThumbnail::class, 2);
        $this->artisan('media-studio:renditions:reconcile')->assertSuccessful();
        Bus::assertDispatchedTimes(GenerateThumbnail::class, 2);
        DB::table('fms_renditions')->update(['last_dispatched_at' => now()->subHours(1)]);
        DB::table('fms_memberships')->update(['role' => 'viewer']);
        $this->artisan('media-studio:renditions:reconcile')->assertSuccessful();
        Bus::assertDispatchedTimes(GenerateThumbnail::class, 2);
        $this->getJson($this->url($workspace))->assertJsonPath('items.0.thumbnailState', 'blocked')->assertJsonPath('items.0.previewState', 'blocked');
        $this->artisan('media-studio:renditions:reconcile', ['--limit' => 0])->assertExitCode(2);
    }

    public function test_existing_minimal_thumbnail_configuration_keeps_working_with_default_recipes(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        config()->set('media-studio.processing', ['thumbnails' => ['edge' => 128]]);
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
        $rendition = DB::table('fms_renditions')->where('preset', 'thumbnail-v1')->first();
        $this->assertSame([128, 64], [(int) $rendition->width, (int) $rendition->height]);
        $this->getJson($this->url($workspace))->assertJsonPath('items.0.previewState', 'ready');
    }

    public function test_conversion_disk_is_separate_and_memory_failure_preserves_original(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        Storage::fake('rendition-output');
        config()->set('media-studio.processing.renditions', [
            'thumbnail-v1' => ['width' => 200, 'height' => 100, 'disk' => 'rendition-output', 'format' => 'webp'],
        ]);
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
        $this->assertCount(1, Storage::disk('studio-test')->allFiles());
        $this->assertCount(1, Storage::disk('rendition-output')->allFiles());
        $card = $this->getJson($this->url($workspace))->json('items.0');
        $this->get($card['thumbnailUrl'])->assertOk()->assertHeader('Content-Type', 'image/webp');
        DB::table('fms_renditions')->update(['state' => 'queued', 'attempts' => 0]);
        config()->set('media-studio.processing.native.max_working_bytes', 1);
        try {
            Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
            $this->fail('The working-memory budget must be enforced.');
        } catch (\RuntimeException $error) {
            $this->assertSame('image_memory_limit', $error->getMessage());
        }
        $this->assertCount(1, Storage::disk('studio-test')->allFiles());
        $this->assertDatabaseHas('fms_renditions', ['failure_code' => 'image_memory_limit', 'state' => 'failed']);
    }

    public function test_corrupted_original_never_produces_a_ready_rendition(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        $version = DB::table('fms_asset_versions')->where('id', $file['versionId'])->first();
        Storage::disk($version->disk)->put($version->object_key, 'replaced contents');
        try {
            Bus::dispatched(GenerateThumbnail::class)->first()->handle(app(ThumbnailProcessor::class));
            $this->fail('Changed bytes must be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertSame('source_changed', $error->getMessage());
        }
        $this->assertDatabaseHas('fms_renditions', ['preset' => 'thumbnail-v1', 'failure_code' => 'source_changed', 'state' => 'failed']);
        $this->getJson($this->url($workspace, 'entries/'.$file['id']))->assertJsonPath('thumbnailUrl', null)->assertJsonPath('previewUrl', null);
    }

    public function test_custom_variants_are_reused_and_reauthorized_at_delivery_without_reading_originals_in_browse(): void
    {
        config()->set('media-studio.processing.native.enabled', false);
        config()->set('media-studio.processing.external_providers.custom', CustomVariantFixture::class);
        Storage::fake('custom-variants');
        $png = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($png);
        $bytes = ob_get_clean();
        Storage::disk('custom-variants')->put('existing/card.png', $bytes);
        app()->instance(CustomVariantFixture::class, $provider = new CustomVariantFixture);
        $provider->variants = [new ExternalVariant('card', 'custom-variants', 'existing/card.png', 'image/png', strlen($bytes), 20, 10),
            new ExternalVariant('large', 'custom-variants', 'existing/card.png', 'image/png', strlen($bytes), 20, 10)];
        $workspace = $this->workspace();
        $file = $this->upload($workspace);
        $principal = Principal::fromUser(auth()->user());
        app(Renditions::class)->syncExternal($principal, $workspace, $file['id'], 'custom', ['thumbnail' => 'card', 'preview' => 'card']);
        $provider->calls = 0;
        $card = $this->getJson($this->url($workspace))->assertOk()->json('items.0');
        $this->assertSame(0, $provider->calls);
        $this->assertSame($bytes, $this->get($card['thumbnailUrl'])->assertOk()->assertHeader('Content-Type', 'image/png')->streamedContent());
        $this->assertSame($bytes, $this->get($card['previewUrl'])->assertOk()->streamedContent());
        $this->postJson($this->url($workspace, 'references/resolve'), ['references' => [['assetId' => $file['id'], 'rendition' => 'card']]])->assertOk()->assertJsonPath('items.0.mimeType', 'image/png');
        $this->assertDatabaseHas('fms_renditions', ['preset' => 'card', 'owned' => false]);
        $this->assertCount(1, Storage::disk('studio-test')->allFiles());
        $this->assertCount(1, Storage::disk('custom-variants')->allFiles());
        $provider->calls = 0;
        $references = array_map(fn ($index) => ['assetId' => $file['id'], 'rendition' => $index % 2 === 0 ? 'card' : 'large'], range(1, 100));
        $this->postJson($this->url($workspace, 'references/resolve'), ['references' => $references])->assertOk()->assertJsonCount(100, 'items');
        $this->assertSame(1, $provider->calls);
        $provider->variants = [];
        $this->get($card['thumbnailUrl'])->assertNotFound();
        $this->postJson($this->url($workspace, 'references/resolve'), ['references' => [['assetId' => $file['id'], 'rendition' => 'card']]])->assertNotFound();
        $this->get($card['previewUrl'])->assertNotFound();
        app(Renditions::class)->syncExternal($principal, $workspace, $file['id'], 'custom', ['thumbnail' => 'card', 'preview' => 'card']);
        $this->getJson($this->url($workspace))->assertJsonPath('items.0.thumbnailUrl', null)->assertJsonPath('items.0.previewUrl', null);
        $this->assertCount(1, Storage::disk('custom-variants')->allFiles());
    }
}

final class CustomVariantFixture implements ExternalVariantProvider
{
    public array $variants = [];

    public int $calls = 0;

    public function variants(object $workspace, object $entry, object $version, Principal $principal): iterable
    {
        $this->calls++;

        return $this->variants;
    }
}

final class CountingRenditionDisk extends FilesystemAdapter
{
    public int $reads = 0;

    public bool $expireOnWrite = false;

    public function readStream($path)
    {
        $this->reads++;

        return parent::readStream($path);
    }

    public function writeStream($path, $resource, array $options = [])
    {
        if ($this->expireOnWrite) {
            DB::table('fms_renditions')->where('state', 'processing')->update(['lease_expires_at' => now()->subMinute()]);
        }

        return parent::writeStream($path, $resource, $options);
    }
}

final class RenditionTestPost extends Model {}
