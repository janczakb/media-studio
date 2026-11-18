<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Integrations;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationCatalog;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Integrations\Spatie\SourceAccess;
use UseFlex\MediaStudio\Tests\TestCase;

final class CatalogItem extends Model implements HasMedia
{
    use HasUuids;
    use InteractsWithMedia;

    protected $guarded = [];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos')->useDisk('catalog-originals')->storeConversionsOnDisk('catalog-conversions');
        $this->addMediaCollection('documents')->useDisk('catalog-originals');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('card')->width(16)->performOnCollections('photos')->nonQueued();
        $this->addMediaConversion('reading')->width(32)->format('webp')->performOnCollections('photos')->nonQueued();
        $this->addMediaConversion('later')->width(8)->performOnCollections('photos')->queued();
    }
}

final class CatalogMedia extends Media
{
    use HasUuids;

    protected $table = 'catalog_media';

    protected $primaryKey = 'asset_key';
}

final class CatalogPaths implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return 'catalog/'.$media->collection_name.'/'.$media->getKey().'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getPath($media).'derivatives/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getPath($media).'responsive/';
    }
}

final class SpatieIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), MediaLibraryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-library.media_model', CatalogMedia::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'filesystems.disks.catalog-originals' => ['driver' => 'local', 'root' => storage_path('app/catalog-originals')],
            'filesystems.disks.catalog-conversions' => ['driver' => 'local', 'root' => storage_path('app/catalog-conversions')],
            'media-library.path_generator' => CatalogPaths::class,
            'media-library.image_driver' => 'gd',
            'media-studio.integrations.spatie.mounts.catalog' => [
                'label' => 'Catalog',
                'thumbnail_conversion' => 'card',
                'preview_conversion' => 'reading',
                'scope' => static function (Builder $query, object $workspace, Principal $principal): Builder {
                    $ownsItem = static fn (Builder $owner): Builder => $owner->where('account', $principal->id)->where('workspace_public_id', $workspace->public_id);

                    return $query->where('collection_name', 'photos')->whereHasMorph('model', [CatalogItem::class], $ownsItem)
                        ->orWhere(fn (Builder $documents): Builder => $documents->where('collection_name', 'documents')->whereHasMorph('model', [CatalogItem::class], $ownsItem));
                },
            ],
        ]);
        Schema::create('catalog_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('account');
            $table->ulid('workspace_public_id');
            $table->timestamps();
        });
        Schema::create('catalog_media', function (Blueprint $table): void {
            $table->uuid('asset_key')->primary();
            $table->uuidMorphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });
        Storage::fake('catalog-originals');
        Storage::fake('catalog-conversions');
        Queue::fake([PerformConversionsJob::class]);
    }

    public function test_global_application_catalog_reads_real_spatie_models_conversions_and_permissions_without_workspaces(): void
    {
        (require __DIR__.'/../../packages/laravel/database/application-migrations/2026_11_08_000001_application_files.php')->up();
        Gate::define('catalog.global', fn () => true);
        config()->set('media-studio.application_files', ['enabled' => true, 'sources' => ['catalog' => [
            'type' => 'spatie', 'revision' => 'v1', 'permission' => 'catalog.global',
            'scope' => fn (Builder $query, $tenant, Principal $principal) => $query->whereHasMorph('model', [CatalogItem::class], fn (Builder $owner) => $owner->where('account', $principal->id)),
            'thumbnail_conversion' => 'card', 'preview_conversion' => 'reading',
        ]]]);
        $item = CatalogItem::create(['account' => 'member-uuid-001', 'workspace_public_id' => 'no-workspace-required']);
        $media = $this->addPhoto($item);
        $result = app(ApplicationCatalog::class)->indexSpatie(new Principal('user', 'member-uuid-001'), 'catalog');
        $this->assertSame(1, $result['indexed']);
        $base = '/media-studio/api/v1/application-files/catalog';
        $model = $this->getJson($base)->assertOk()->json('items.0.id');
        $collection = $this->getJson($base.'?parent='.$model)->assertOk()->json('items.0.id');
        $file = $this->getJson($base.'?parent='.$collection)->assertOk()->json('items.0');
        $response = $this->get($file['thumbnailUrl'])->assertOk();
        $this->assertSame(16, getimagesizefromstring($response->streamedContent())[0]);
        $response = $this->get($file['previewUrl'])->assertOk();
        $this->assertSame('image/webp', getimagesizefromstring($response->streamedContent())['mime']);
        $this->assertDatabaseCount('fms_workspaces', 0);
        $this->assertDatabaseCount('fms_entries', 0);
        $media->delete();
        $this->getJson($base.'?parent='.$collection)->assertOk()->assertJsonCount(0, 'items');
        $this->get($file['thumbnailUrl'])->assertNotFound();
    }

    public function test_native_spatie_uploads_are_projected_in_scoped_pages_and_streamed_from_their_disks(): void
    {
        $workspace = $this->workspace();
        $catalog = CatalogItem::create(['account' => 'member-uuid-001', 'workspace_public_id' => $workspace]);
        $other = CatalogItem::create(['account' => 'another-principal', 'workspace_public_id' => $workspace]);
        $photo = $this->addPhoto($catalog);
        $document = $catalog->addMediaFromString('existing document')->usingFileName('catalog.txt')->toMediaCollection('documents');
        $hidden = $this->addPhoto($other);
        $this->assertInstanceOf(CatalogMedia::class, $photo);
        $this->assertTrue($photo->hasGeneratedConversion('card'));
        $this->assertTrue($photo->hasGeneratedConversion('reading'));
        $this->assertFalse($photo->hasGeneratedConversion('later'));
        Queue::assertPushed(PerformConversionsJob::class, 2);
        $originalPaths = Storage::disk('catalog-originals')->allFiles();
        $conversionPaths = Storage::disk('catalog-conversions')->allFiles();
        $original = Storage::disk('catalog-originals')->get($photo->getPathRelativeToRoot());
        $converted = Storage::disk('catalog-conversions')->get($photo->getPathRelativeToRoot('card'));
        $this->assertSame('image/jpeg', getimagesizefromstring($converted)['mime']);
        $this->assertSame(16, getimagesizefromstring($converted)[0]);

        $sync = $this->url($workspace, 'sources/spatie/catalog/sync');
        $this->postJson($sync, ['limit' => 121])->assertUnprocessable();
        $first = $this->postJson($sync, ['limit' => 1])->assertOk()->assertJsonPath('processed', 1)->json();
        $this->assertNotNull($first['nextCursor']);
        $this->assertDatabaseCount('fms_asset_versions', 1);
        $this->postJson($sync, ['limit' => 1, 'cursor' => $first['nextCursor']])->assertOk()->assertExactJson(['processed' => 1, 'nextCursor' => null]);
        $this->postJson($sync, ['cursor' => $first['nextCursor'].'invalid'])->assertUnprocessable();
        $otherWorkspace = $this->workspace('Other library');
        $this->postJson($this->url($otherWorkspace, 'sources/spatie/catalog/sync'), ['cursor' => $first['nextCursor']])->assertUnprocessable();
        $this->postJson($this->url($otherWorkspace, 'sources/spatie/catalog/sync'))->assertOk()->assertExactJson(['processed' => 0, 'nextCursor' => null]);
        $this->assertDatabaseMissing('fms_spatie_sources', ['media_key' => $hidden->getKey()]);
        $this->assertDatabaseHas('fms_spatie_sources', ['media_key' => $document->getKey()]);

        $folder = $this->getJson($this->url($workspace))->assertOk()->assertJsonCount(1, 'items')->json('items.0');
        $this->assertTrue($folder['readOnly']);
        $cards = $this->getJson($this->url($workspace).'?parent='.$folder['id'])->assertOk()->assertJsonCount(2, 'items')->json('items');
        $file = collect($cards)->firstWhere('mimeType', 'image/png');
        $this->assertTrue($file['readOnly']);
        $url = $this->url($workspace, 'entries/'.$file['id']);
        $this->getJson($url)->assertOk()->assertJsonPath('metadata.alt', 'Source alt');
        $this->assertSame($original, $this->get($url.'/content')->assertOk()->assertHeader('Content-Type', 'image/png')->streamedContent());
        $this->assertSame($converted, $this->get($file['thumbnailUrl'])->assertOk()->assertHeader('Content-Type', 'image/jpeg')->streamedContent());
        $previewBytes = Storage::disk('catalog-conversions')->get($photo->getPathRelativeToRoot('reading'));
        $this->assertSame($previewBytes, $this->get($file['previewUrl'])->assertOk()->assertHeader('Content-Type', 'image/webp')->streamedContent());
        $indexed = DB::table('fms_entries')->where('public_id', $file['id'])->first();
        $scope = DB::table('fms_workspaces')->where('public_id', $workspace)->first();
        $this->assertSame('ready', $indexed->preview_state);
        $this->assertSame($photo->getPathRelativeToRoot('reading'), $indexed->preview_key);
        $this->assertSame('catalog-conversions', $indexed->preview_disk);
        $this->assertSame('image/webp', app(SourceAccess::class)->previewMime($scope, new Principal('user', 'member-uuid-001'), $indexed));
        $this->assertDatabaseCount('fms_renditions', 0);
        $this->patchJson($url, ['revision' => $file['revision'], 'name' => 'Studio rename'])->assertForbidden();
        $this->postJson($sync)->assertOk()->assertExactJson(['processed' => 2, 'nextCursor' => null]);
        $this->getJson($url)->assertOk()->assertJsonPath('revision', $file['revision'])->assertJsonPath('versionId', $file['versionId']);
        $this->assertDatabaseCount('fms_asset_versions', 2);
        $this->assertSame('native-photo.png', $photo->fresh()->file_name);
        $this->assertSame($originalPaths, Storage::disk('catalog-originals')->allFiles());
        $this->assertSame($conversionPaths, Storage::disk('catalog-conversions')->allFiles());
        $this->assertSame([], Storage::disk('studio-test')->allFiles());
        Queue::assertPushed(PerformConversionsJob::class, 2);
    }

    public function test_live_source_changes_revocation_and_native_deletion_stop_access(): void
    {
        $workspace = $this->workspace();
        $catalog = CatalogItem::create(['account' => 'member-uuid-001', 'workspace_public_id' => $workspace]);
        $photo = $this->addPhoto($catalog);
        $catalog->addMediaFromString('next page')->usingFileName('next.txt')->toMediaCollection('documents');
        $sync = $this->url($workspace, 'sources/spatie/catalog/sync');
        $cursor = $this->postJson($sync, ['limit' => 1])->assertOk()->json('nextCursor');
        $this->postJson($sync)->assertOk();
        $file = $this->getJson($this->url($workspace).'?view=photos')->assertOk()->assertJsonCount(1, 'items')->json('items.0');
        $url = $this->url($workspace, 'entries/'.$file['id']);
        $oldVersion = $url.'/versions/'.$file['versionId'].'/content';
        $this->get($oldVersion)->assertOk();

        $photo->file_name = 'renamed-by-spatie.png';
        $photo->save();
        Storage::disk('catalog-originals')->assertExists($photo->getPathRelativeToRoot());
        $this->get($oldVersion)->assertNotFound();
        $this->get($file['thumbnailUrl'])->assertNotFound();
        $this->postJson($sync)->assertOk();
        $this->get($oldVersion)->assertNotFound();
        $this->getJson($url)->assertOk()->assertJsonPath('name', 'renamed-by-spatie.png');
        $this->get($url.'/content')->assertOk();
        $this->postJson($this->url($workspace, 'references/resolve'), ['references' => [['assetId' => $file['id'], 'versionId' => $file['versionId']]]])->assertNotFound();

        $principal = new Principal('user', 'another-principal');
        $membership = ['workspace_id' => DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'), 'principal_type' => $principal->type,
            'principal_id' => $principal->id, 'principal_key' => $principal->key(), 'role' => 'viewer'];
        DB::table('fms_memberships')->insert($membership);
        $this->actingAs(new GenericUser(['id' => $principal->id]));
        $this->postJson($sync)->assertForbidden();
        DB::table('fms_memberships')->where('principal_key', $principal->key())->update(['role' => 'editor']);
        $this->postJson($sync, ['cursor' => $cursor])->assertUnprocessable();
        $this->postJson($sync)->assertOk()->assertExactJson(['processed' => 0, 'nextCursor' => null]);
        $this->getJson($url)->assertNotFound();
        $this->get($url.'/content')->assertNotFound();

        $this->actingAs(new GenericUser(['id' => 'member-uuid-001']));
        $catalog->update(['account' => 'another-principal']);
        $this->get($url.'/content')->assertNotFound();
        $catalog->update(['account' => 'member-uuid-001']);
        $this->get($url.'/content')->assertOk();
        $photo->delete();
        $this->getJson($this->url($workspace).'?view=photos')->assertOk()->assertJsonCount(0, 'items');
        $this->get($url.'/content')->assertNotFound();
        Storage::disk('catalog-originals')->assertMissing($photo->getPathRelativeToRoot());
        $this->assertDatabaseCount('catalog_media', 1);

        CatalogMedia::creating(static function (CatalogMedia $media) use ($photo): void {
            $media->setAttribute($media->getKeyName(), $photo->getKey());
        });
        $replacement = $this->addPhoto($catalog);
        $this->assertSame($photo->getKey(), $replacement->getKey());
        $this->assertNotSame($photo->uuid, $replacement->uuid);
        $this->postJson($sync)->assertOk();
        $this->getJson($url)->assertNotFound();
        $this->get($url.'/content')->assertNotFound();
        $replaced = $this->getJson($this->url($workspace).'?view=photos')->assertOk()->assertJsonCount(1, 'items')->json('items.0');
        $this->assertNotSame($file['id'], $replaced['id']);

        auth()->forgetUser();
        $this->postJson($sync)->assertUnauthorized();
    }

    private function addPhoto(CatalogItem $catalog): CatalogMedia
    {
        return $catalog->addMedia(UploadedFile::fake()->image('native-photo.png', 48, 32))
            ->withCustomProperties(['alt' => 'Source alt'])->toMediaCollection('photos');
    }
}
