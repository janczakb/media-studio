<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Access\TenantResolver;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationCatalog;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationMedia;
use UseFlex\MediaStudio\ApplicationFiles\ApplicationMediaObject;
use UseFlex\MediaStudio\ApplicationFiles\RegistersApplicationMedia;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class ApplicationFilesTest extends TestCase
{
    private const BASE = '/media-studio/api/v1/application-files';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../packages/laravel/database/application-migrations/*.php') as $migration) {
            (require $migration)->up();
        }
        config()->set('filesystems.disks.studio-test', ['driver' => 'local', 'root' => storage_path('app/studio-test')]);
        config()->set('media-studio.application_files', ['enabled' => true, 'sources' => [
            'avatars' => ['type' => 'disk', 'allow_unregistered' => true, 'revision' => 'v1', 'label' => 'User avatars', 'disk' => 'studio-test', 'prefix' => 'avatars', 'permission' => 'application.avatars'],
        ]]);
        Gate::define('application.avatars', fn () => true);
        Storage::disk('studio-test')->put('avatars/users/a.txt', 'Application owned');
    }

    private function principal(): Principal
    {
        return new Principal('user', 'member-uuid-001');
    }

    private function index(): void
    {
        app(ApplicationCatalog::class)->indexDisk($this->principal(), 'avatars', ['avatars/users/a.txt']);
    }

    private function file(): array
    {
        $folder = $this->getJson(self::BASE.'/avatars')->assertOk()->json('items.0.id');

        return $this->getJson(self::BASE.'/avatars?parent='.$folder)->assertOk()->json('items.0');
    }

    public function test_catalog_is_workspace_independent_idempotent_and_has_no_mutation_surface(): void
    {
        $this->index();
        $this->index();
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('items.0.id', 'avatars')->assertJsonPath('items.0.readOnly', true);
        $file = $this->file();
        $this->assertSame('a.txt', $file['name']);
        $this->assertTrue($file['readOnly']);
        $this->assertArrayNotHasKey('disk', $file);
        $this->assertNull($file['thumbnailUrl']);
        $this->assertDatabaseCount('fms_workspaces', 0);
        $this->assertDatabaseCount('fms_entries', 0);
        $this->assertDatabaseCount('fms_asset_versions', 0);
        $this->assertDatabaseCount('fms_application_entries', 2);
        $this->assertFalse(Schema::hasColumn('fms_application_entries', 'workspace_id'));
        $download = $this->get($file['downloadUrl'])->assertOk();
        $this->assertSame('Application owned', $download->streamedContent());
        $workspace = $this->workspace();
        $this->getJson($this->url($workspace))->assertOk()->assertJsonCount(0, 'items');
        $this->postJson($this->url($workspace, 'entries/'.$file['id'].'/trash'), ['revision' => 1])->assertNotFound();
        $this->patchJson(self::BASE.'/avatars/entries/'.$file['id'], ['name' => 'changed.txt'])->assertStatus(405);
        Storage::disk('studio-test')->assertExists('avatars/users/a.txt');
    }

    public function test_department_source_download_and_index_permissions_are_independent(): void
    {
        $this->index();
        $file = $this->file();
        Gate::define('studio.application-files.download', fn () => false);
        $this->assertNull($this->file()['downloadUrl']);
        $this->get($file['downloadUrl'])->assertForbidden();
        Gate::define('application.avatars', fn () => false);
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(self::BASE.'/avatars')->assertNotFound();
        $this->get($file['downloadUrl'])->assertNotFound();
        Gate::define('studio.application-files.view', fn () => false);
        $this->getJson(self::BASE)->assertForbidden();
    }

    public function test_application_source_download_does_not_require_a_workspace_download_permission(): void
    {
        $this->index();
        $file = $this->file();
        Gate::define('studio.files.download', fn () => false);
        $this->get(self::BASE.'/avatars/entries/'.$file['id'].'/original?download=1')->assertOk();
        Gate::define('studio.application-files.download', fn () => false);
        $this->get(self::BASE.'/avatars/entries/'.$file['id'].'/original?download=1')->assertForbidden();
    }

    public function test_changed_or_deleted_objects_and_changed_source_config_do_not_deliver_stale_content(): void
    {
        $this->index();
        $url = $this->file()['downloadUrl'];
        Storage::disk('studio-test')->put('avatars/users/a.txt', 'A different longer object');
        $this->get($url)->assertStatus(409);
        $this->index();
        $this->get($url)->assertOk();
        Storage::disk('studio-test')->delete('avatars/users/a.txt');
        $this->get($url)->assertNotFound();
        config()->set('media-studio.application_files.sources.avatars.revision', 'v2');
        $this->get($url)->assertNotFound();
        $this->getJson(self::BASE.'/avatars')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_indexing_rejects_paths_outside_source_and_index_permission_is_required(): void
    {
        $this->expectException(HttpException::class);
        app(ApplicationCatalog::class)->indexDisk($this->principal(), 'avatars', ['avatars/../private.txt']);
    }

    public function test_owner_invalidation_only_removes_catalog_references_and_indexing_requires_its_own_permission(): void
    {
        $this->index();
        $file = $this->file();
        app(ApplicationCatalog::class)->forgetDisk($this->principal(), 'avatars', 'avatars/users/a.txt');
        Storage::disk('studio-test')->assertExists('avatars/users/a.txt');
        $this->get($file['downloadUrl'])->assertNotFound();
        Gate::define('studio.application-files.index', fn () => false);
        $this->expectException(HttpException::class);
        $this->index();
    }

    public function test_keyset_cursor_is_bound_to_source_parent_principal_and_tenant(): void
    {
        $this->index();
        Storage::disk('studio-test')->put('avatars/users/b.txt', 'Second');
        app(ApplicationCatalog::class)->indexDisk($this->principal(), 'avatars', ['avatars/users/b.txt']);
        $folder = $this->getJson(self::BASE.'/avatars')->json('items.0.id');
        $page = $this->getJson(self::BASE.'/avatars?parent='.$folder.'&limit=1')->assertOk();
        $cursor = $page->json('nextCursor');
        $this->assertIsString($cursor);
        $this->getJson(self::BASE.'/avatars?parent='.$folder.'&cursor='.urlencode($cursor))->assertOk()->assertJsonPath('items.0.name', 'b.txt');
        $this->getJson(self::BASE.'/avatars?cursor='.urlencode($cursor))->assertStatus(422);
        $this->actingAs(new GenericUser(['id' => 'another-user']));
        $this->getJson(self::BASE.'/avatars?parent='.$folder.'&cursor='.urlencode($cursor))->assertStatus(422);
    }

    public function test_tenant_is_resolved_on_the_server_and_not_from_client_workspace_or_query(): void
    {
        $resolver = new ApplicationTenantFixture;
        app()->instance(ApplicationTenantFixture::class, $resolver);
        config()->set('media-studio.tenancy.resolver', ApplicationTenantFixture::class);
        config()->set('media-studio.application_files.sources.avatars.prefix', fn (?TenantIdentity $tenant) => 'tenants/'.$tenant->id);
        Storage::disk('studio-test')->put('tenants/a/a.txt', 'A');
        Storage::disk('studio-test')->put('tenants/b/b.txt', 'B');
        $resolver->active = 'a';
        app(ApplicationCatalog::class)->indexDisk($this->principal(), 'avatars', ['tenants/a/a.txt']);
        $first = $this->getJson(self::BASE.'/avatars')->assertOk()->json('items.0');
        $resolver->active = 'b';
        app(ApplicationCatalog::class)->indexDisk($this->principal(), 'avatars', ['tenants/b/b.txt']);
        $this->getJson(self::BASE.'/avatars?tenant=a&workspace=anything')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.name', 'b.txt');
        $this->get($first['downloadUrl'])->assertNotFound();
        $this->assertDatabaseCount('fms_workspaces', 0);
        $resolver->authorized = false;
        $this->getJson(self::BASE)->assertNotFound();
    }

    public function test_combined_catalog_filters_sources_keeps_same_named_folders_isolated_and_rechecks_neighbors(): void
    {
        $source = config('media-studio.application_files.sources.avatars');
        config()->set('media-studio.application_files.sources.documents', [...$source, 'prefix' => 'documents', 'label' => 'Application documents']);
        Storage::disk('studio-test')->put('documents/users/b.txt', 'Other source');
        Storage::disk('studio-test')->put('documents/root.txt', 'Root');
        $this->index();
        app(ApplicationCatalog::class)->indexDisk($this->principal(), 'documents', ['documents/users/b.txt', 'documents/root.txt']);
        $root = $this->getJson(self::BASE.'/catalog')->assertOk()->assertJsonCount(3, 'items')->json('items');
        $folders = array_values(array_filter($root, fn ($row) => $row['kind'] === 'folder'));
        $this->assertCount(2, $folders);
        foreach ($folders as $folder) {
            $page = $this->getJson(self::BASE.'/catalog?parent='.$folder['id'])->assertOk()->assertJsonCount(1, 'items');
            $this->assertSame($folder['sourceKey'], $page->json('items.0.sourceKey'));
        }
        $this->getJson(self::BASE.'/catalog?source=documents')->assertOk()->assertJsonCount(2, 'items');
        $this->getJson(self::BASE.'/catalog?source=avatars&search=USER')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson(self::BASE.'/catalog?search=%')->assertOk()->assertJsonCount(0, 'items');
        $first = $this->getJson(self::BASE.'/catalog?limit=1')->assertOk();
        $this->getJson(self::BASE.'/catalog?source=documents&cursor='.urlencode($first->json('nextCursor')))->assertStatus(422);
        $file = $this->file();
        $this->getJson(self::BASE.'/catalog/entries/'.$file['id'])->assertOk()->assertJsonPath('name', 'a.txt');
        $this->getJson(self::BASE.'/catalog/entries/'.$file['id'].'/neighbors?direction=next')->assertOk()->assertJsonCount(0, 'items');
        Gate::define('application.avatars', fn () => false);
        $this->getJson(self::BASE.'/catalog')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(self::BASE.'/catalog/entries/'.$file['id'])->assertNotFound();
    }

    public function test_listing_does_not_touch_storage_and_bounded_pages_do_not_count_all_rows(): void
    {
        $this->index();
        Storage::shouldReceive('disk')->never();
        DB::enableQueryLog();
        $this->getJson(self::BASE.'/avatars')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertFalse(collect($queries)->contains(fn (array $query) => str_contains(strtolower($query['query']), 'count(')));
        $this->assertLessThan(10, count($queries));
    }

    public function test_unregistered_disk_sources_are_invisible_even_if_they_were_indexed_before(): void
    {
        $this->index();
        $file = $this->file();
        config()->set('media-studio.application_files.sources.avatars.allow_unregistered', null);

        $this->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(self::BASE.'/catalog')->assertOk()->assertJsonCount(0, 'items');
        $this->get($file['downloadUrl'])->assertNotFound();
        $this->assertDatabaseCount('fms_application_entries', 2);
        Storage::disk('studio-test')->assertExists('avatars/users/a.txt');

        $this->expectException(HttpException::class);
        $this->index();
    }

    private function registerPostMedia(): void
    {
        Schema::create('application_post_fixture', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('owner');
            $table->string('revision');
            $table->boolean('has_cover')->default(true);
            $table->boolean('variants_ready')->default(true);
            $table->string('prefix')->default('flat');
        });
        Storage::fake('studio-variants');
        config()->set('filesystems.disks.studio-variants', ['driver' => 'local', 'root' => storage_path('app/studio-variants')]);
        config()->set('media-studio.application_files.sources.posts', [
            'type' => 'model', 'model' => ApplicationPostFixture::class, 'revision' => 'v1',
            'permission' => 'application.avatars', 'stores' => ['studio-test' => 'flat', 'studio-variants' => 'flat'],
            'scope' => fn (Builder $query, ?TenantIdentity $tenant, Principal $principal) => $query->where('owner', $principal->id),
        ]);
    }

    private function postFixture(string $id): ApplicationPostFixture
    {
        return ApplicationPostFixture::create(['id' => $id, 'owner' => $this->principal()->id, 'revision' => 'v1'])->refresh();
    }

    public function test_model_media_groups_a_flat_library_and_serves_the_explicit_roles_from_two_disks(): void
    {
        $this->registerPostMedia();
        $post = $this->postFixture('post-42');
        $cover = iterator_to_array($post->studioMedia())[0];
        Storage::disk('studio-test')->put($cover->original->path, 'original');
        Storage::disk('studio-test')->put('flat/unregistered.jpg', 'ignored');
        foreach ($cover->variants as $name => $variant) {
            Storage::disk('studio-variants')->put($variant->path, substr($name, strlen('size-')));
        }
        $catalog = app(ApplicationCatalog::class);
        $catalog->syncModel($this->principal(), 'posts', $post->id);
        $catalog->syncModel($this->principal(), 'posts', $post->id);

        $file = $this->getJson(self::BASE.'/catalog?source=posts')->assertOk()->assertJsonCount(1, 'items')->json('items.0');
        $this->assertSame('post-42-v1.jpg', $file['name']);
        $this->assertStringNotContainsString('flat/', json_encode($file));
        $this->assertSame('320', $this->get($file['thumbnailUrl'])->assertOk()->streamedContent());
        $this->assertSame('1900', $this->get($file['previewUrl'])->assertOk()->streamedContent());
        $this->assertSame('original', $this->get($file['downloadUrl'])->assertOk()->streamedContent());
        $this->assertDatabaseCount('fms_application_entries', 1);
        $this->assertDatabaseCount('fms_workspaces', 0);
        $this->assertDatabaseCount('fms_renditions', 0);
    }

    public function test_model_sync_rejects_stale_versions_preserves_the_card_and_revokes_removed_slots(): void
    {
        $this->registerPostMedia();
        $post = $this->postFixture('post-42');
        $catalog = app(ApplicationCatalog::class);
        $catalog->syncModel($this->principal(), 'posts', $post->id);
        $first = $this->getJson(self::BASE.'/posts')->assertOk()->json('items.0');
        $post->update(['revision' => 'v2', 'variants_ready' => false]);
        $this->get($first['thumbnailUrl'])->assertConflict();
        $catalog->syncModel($this->principal(), 'posts', $post->id);
        $second = $this->getJson(self::BASE.'/posts')->assertOk()->assertJsonPath('items.0.id', $first['id'])
            ->assertJsonPath('items.0.thumbnailUrl', null)->json('items.0');
        $this->assertNotSame($first['versionId'], $second['versionId']);
        $this->get($first['thumbnailUrl'])->assertNotFound();
        $post->update(['variants_ready' => true]);
        $catalog->syncModel($this->principal(), 'posts', $post->id);
        $this->getJson(self::BASE.'/posts')->assertOk()->assertJsonPath('items.0.thumbnailState', 'ready');
        $post->update(['has_cover' => false]);
        $this->get($second['downloadUrl'])->assertNotFound();
        $catalog->syncModel($this->principal(), 'posts', $post->id);
        $this->getJson(self::BASE.'/posts')->assertOk()->assertJsonCount(0, 'items');
        $this->assertDatabaseCount('fms_application_entries', 0);
    }

    public function test_model_listing_authorizes_in_one_bounded_query_without_resolving_files(): void
    {
        $this->registerPostMedia();
        foreach (['a', 'b', 'c'] as $id) {
            $this->postFixture($id);
        }
        $catalog = app(ApplicationCatalog::class);
        $this->assertSame(['indexed' => 2, 'after' => 'b', 'hasMore' => true], $catalog->indexModels($this->principal(), 'posts', null, 2));
        $this->assertSame(['indexed' => 1, 'after' => 'c', 'hasMore' => false], $catalog->indexModels($this->principal(), 'posts', 'b', 2));
        $first = $this->getJson(self::BASE.'/posts')->assertOk()->assertJsonCount(3, 'items')->json('items.0');
        ApplicationPostFixture::whereKey('a')->update(['owner' => 'another-user']);
        $this->get($first['downloadUrl'])->assertNotFound();
        Storage::shouldReceive('disk')->never();
        DB::enableQueryLog();
        $this->getJson(self::BASE.'/posts')->assertOk()->assertJsonCount(2, 'items');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, array_filter($queries, fn (array $query) => str_contains($query['query'], 'application_post_fixture')));
    }

    public function test_model_index_rejects_a_variant_outside_the_registered_prefix_atomically(): void
    {
        $this->registerPostMedia();
        $post = $this->postFixture('a');
        $post->update(['prefix' => 'private']);

        try {
            app(ApplicationCatalog::class)->indexModels($this->principal(), 'posts');
            $this->fail('An unregistered prefix must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('fms_application_entries', 0);
    }

    public function test_model_deletion_cleanup_preserves_current_records_and_does_not_touch_storage(): void
    {
        $this->registerPostMedia();
        $post = $this->postFixture('a');
        $catalog = app(ApplicationCatalog::class);
        $catalog->syncModel($this->principal(), 'posts', $post->id);
        Storage::shouldReceive('disk')->never();
        $catalog->forgetDeletedModel($this->principal(), 'posts', $post->id);
        $this->assertDatabaseCount('fms_application_entries', 1);
        $post->delete();
        $this->getJson(self::BASE.'/posts')->assertOk()->assertJsonCount(0, 'items');
        $catalog->forgetDeletedModel($this->principal(), 'posts', $post->id);
        $this->assertDatabaseCount('fms_application_entries', 0);
    }

    public function test_million_model_assets_keep_listing_and_authorization_bounded(): void
    {
        if (getenv('STUDIO_LARGE_BENCHMARK') !== '1') {
            $this->markTestSkipped('Enable STUDIO_LARGE_BENCHMARK=1 for the million-entry model catalog.');
        }
        $this->registerPostMedia();
        config()->set('media-studio.application_files.sources.posts.scope', static fn (Builder $query, ?TenantIdentity $tenant, Principal $actor) => $query->where('owner', $actor->id)->orWhere('owner', 'public'));
        $source = app(ApplicationCatalog::class)->source($this->principal(), 'posts', true);
        $series = 'WITH RECURSIVE sequence(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM sequence WHERE n < 1000000)';
        DB::statement($series." INSERT INTO application_post_fixture (id, owner, revision)
            SELECT printf('%08d', n), ?, 'v1' FROM sequence", [$this->principal()->id]);
        DB::statement($series." INSERT INTO fms_application_entries
            (public_id, tenant_scope, source_key, source_revision, identity, parent_key, kind, name, sort_name, remote_id, descriptor)
            SELECT printf('%026d', n), ?, 'posts', ?, printf('%064d', n), ?, 'file', printf('post-%08d.jpg', n),
                printf('post-%08d.jpg', n), printf('%08d', n), ? FROM sequence",
            [$source->tenantScope(), $source->revision(), hash('sha256', 'root'), json_encode(['mime' => 'image/jpeg', 'size' => 1000])]);

        Storage::shouldReceive('disk')->never();
        $times = [];
        $cursor = null;
        DB::enableQueryLog();
        try {
            for ($page = 0; $page < 20; $page++) {
                DB::flushQueryLog();
                $start = hrtime(true);
                $response = $this->getJson(self::BASE.'/catalog?source=posts&limit=60'.($cursor === null ? '' : '&cursor='.urlencode($cursor)))
                    ->assertOk()->assertJsonCount(60, 'items');
                $times[] = (hrtime(true) - $start) / 1e6;
                $cursor = $response->json('nextCursor');
                $queries = DB::getQueryLog();
                $this->assertIsString($cursor);
                $this->assertCount(1, array_filter($queries, fn (array $query) => str_contains($query['query'], 'application_post_fixture')));
                $this->assertFalse(collect($queries)->contains(fn (array $query) => str_contains(strtolower($query['query']), 'count(')));
            }
        } finally {
            DB::disableQueryLog();
        }
        sort($times);
        fwrite(STDOUT, json_encode(['catalogRows' => 1000000, 'engine' => 'SQLite in-memory', 'pageSize' => 60, 'pages' => count($times),
            'p50Ms' => round(($times[9] + $times[10]) / 2, 2), 'p95Ms' => round($times[18], 2)], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_spatie_visibility_is_rechecked_in_a_single_scoped_batch_and_deleted_media_disappear(): void
    {
        Schema::create('application_media_fixture', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('tenant');
            $table->string('model_type');
            $table->string('collection_name');
            $table->string('disk');
            $table->string('mime_type');
            $table->string('file_name');
            $table->integer('size');
            $table->timestamps();
        });
        config()->set('media-studio.application_files.sources.media', ['type' => 'spatie', 'revision' => 'v1', 'permission' => 'application.avatars', 'media_model' => ApplicationMediaFixture::class,
            'scope' => fn (Builder $query, ?TenantIdentity $tenant, Principal $principal) => $query->where('tenant', $principal->id)]);
        foreach (['a', 'b'] as $id) {
            ApplicationMediaFixture::create(['id' => $id, 'tenant' => 'member-uuid-001', 'model_type' => 'Post', 'collection_name' => 'gallery', 'disk' => 'studio-test', 'mime_type' => 'text/plain', 'file_name' => $id.'.txt', 'size' => 7]);
            Storage::disk('studio-test')->put('media/'.$id.'.txt', 'fixture');
        }
        app(ApplicationCatalog::class)->indexSpatie($this->principal(), 'media');
        $model = $this->getJson(self::BASE.'/media')->json('items.0.id');
        $collection = $this->getJson(self::BASE.'/media?parent='.$model)->json('items.0.id');
        $page = $this->getJson(self::BASE.'/media?parent='.$collection)->assertOk()->assertJsonCount(2, 'items');
        ApplicationMediaFixture::whereKey('a')->delete();
        ApplicationMediaFixture::whereKey('b')->update(['tenant' => 'other']);
        $this->getJson(self::BASE.'/media?parent='.$collection)->assertOk()->assertJsonCount(0, 'items');
        foreach ($page->json('items') as $file) {
            $this->get($file['downloadUrl'])->assertNotFound();
        }
        $this->assertDatabaseCount('fms_workspaces', 0);
    }
}

final class ApplicationTenantFixture implements TenantResolver
{
    public string $active = 'a';

    public bool $authorized = true;

    public function current(Principal $principal): ?TenantIdentity
    {
        return new TenantIdentity('company', $this->active);
    }

    public function allows(Principal $principal, TenantIdentity $tenant): bool
    {
        return $this->authorized;
    }
}

final class ApplicationMediaFixture extends Model
{
    protected $table = 'application_media_fixture';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function getPathRelativeToRoot(): string
    {
        return 'media/'.$this->getKey().'.txt';
    }
}

final class ApplicationPostFixture extends Model implements RegistersApplicationMedia
{
    protected $table = 'application_post_fixture';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function studioMedia(): iterable
    {
        if (! $this->has_cover) {
            return;
        }
        $stem = $this->id.'-'.$this->revision;
        $variants = [];
        if ($this->variants_ready) {
            foreach ([320, 640, 1000, 1600, 1900] as $edge) {
                $variants['size-'.$edge] = new ApplicationMediaObject('studio-variants', $this->prefix.'/'.$stem.'-'.$edge.'.avif', 'image/avif', strlen((string) $edge));
            }
        }
        yield new ApplicationMedia('cover', $this->revision, new ApplicationMediaObject('studio-test', 'flat/'.$stem.'.jpg', 'image/jpeg', 8),
            variants: $variants, thumbnail: 'size-320', preview: 'size-1900');
    }
}
