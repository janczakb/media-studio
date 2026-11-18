<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Integrations\Spatie\SourceAccess;
use UseFlex\MediaStudio\Integrations\Spatie\SourceIndexer;

/** A source-model contract fixture; this is not a real Spatie 11 integration test. */
class SpatieSourceFixture extends Model
{
    protected $table = 'source_media';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function getPathRelativeToRoot(string $conversion = ''): string
    {
        return 'existing/'.$this->getKey().'/'.($conversion === '' ? $this->file_name : 'conversions/'.$conversion.'.webp');
    }

    public function hasGeneratedConversion(string $conversion): bool
    {
        return $conversion === 'thumb' && $this->getKey() === 'a-01';
    }
}

final class SpatieSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('source_media', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('tenant');
            $table->string('file_name');
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
        config()->set('media-studio.integrations.spatie.mounts.products', ['media_model' => SpatieSourceFixture::class, 'scope' => fn (Builder $query, object $workspace, Principal $principal): Builder => $query->where('tenant', $principal->id), 'thumbnail_conversion' => 'thumb']);
        Storage::fake('existing-media');
        foreach (['a-01', 'b-02', 'c-03'] as $id) {
            SpatieSourceFixture::create(['id' => $id, 'tenant' => $id === 'c-03' ? 'another-user' : 'member-uuid-001', 'file_name' => 'photo.png', 'disk' => 'existing-media', 'mime_type' => 'image/png', 'size' => 13]);
            Storage::disk('existing-media')->put('existing/'.$id.'/photo.png', 'source-image!');
        }
        Storage::disk('existing-media')->put('existing/a-01/conversions/thumb.webp', 'source-thumbnail');
    }

    public function test_scheduled_sync_discovers_updates_and_reconciles_missing_models(): void
    {
        $workspace = $this->workspace();
        config()->set('media-studio.sources.sync.targets.products', ['kind' => 'spatie', 'workspace' => $workspace, 'mount' => 'products', 'principal_type' => 'user', 'principal_id' => 'member-uuid-001', 'interval_seconds' => 1, 'missing_grace_seconds' => 0]);
        $run = fn () => Artisan::call('media-studio:sources:sync', ['target' => 'products', '--limit' => 1]);
        $this->assertSame(0, $run());
        $this->assertSame(2, DB::table('fms_asset_versions')->count());
        SpatieSourceFixture::whereKey('a-01')->update(['size' => 30]);
        SpatieSourceFixture::whereKey('b-02')->delete();
        $this->travel(2)->seconds();
        $this->assertSame(0, $run());
        $this->assertSame(3, DB::table('fms_asset_versions')->count());
        $this->travel(2)->seconds();
        $this->assertSame(0, $run());
        $this->assertNotNull(DB::table('fms_spatie_sources')->where('media_key', 'b-02')->value('missing_at'));
        $this->assertSame('source-image!', Storage::disk('existing-media')->get('existing/b-02/photo.png'));
    }

    public function test_drawing_overlay_can_be_saved_for_a_readable_external_image_without_mutating_its_source(): void
    {
        $workspace = $this->workspace();
        $principal = new Principal('user', 'member-uuid-001');
        app(SourceIndexer::class)->syncPage($principal, $workspace, 'products');
        $folder = $this->getJson($this->url($workspace))->json('items.0');
        $file = $this->getJson($this->url($workspace).'?parent='.$folder['id'])->json('items.0');
        $url = $this->url($workspace, 'entries/'.$file['id']);
        $drawing = ['versionId' => $file['versionId'], 'marks' => [
            ['tool' => 'line', 'color' => '#4169e1', 'points' => [[10, 10], [800, 700]]],
        ]];
        $this->patchJson($url, ['revision' => $file['revision'], 'drawing' => $drawing])->assertOk();
        $this->getJson($url)->assertJsonPath('metadata.drawing', $drawing);
        $this->assertSame('photo.png', SpatieSourceFixture::findOrFail('a-01')->file_name);
        $this->assertSame('source-image!', Storage::disk('existing-media')->get('existing/a-01/photo.png'));
        SpatieSourceFixture::whereIn('id', ['a-01', 'b-02'])->update(['tenant' => 'revoked']);
        $this->patchJson($url, ['revision' => $file['revision'] + 1, 'drawing' => $drawing])->assertNotFound();
    }

    public function test_source_dimensions_are_indexed_without_decoding_originals(): void
    {
        $workspace = $this->workspace();
        $principal = new Principal('user', 'member-uuid-001');
        config()->set('media-studio.integrations.spatie.mounts.products.dimensions_using', fn ($media) => ['width' => 2400, 'height' => 1600]);
        app(SourceIndexer::class)->syncPage($principal, $workspace, 'products');
        $this->assertSame(2, DB::table('fms_entries')->where('kind', 'file')->where('width', 2400)->where('height', 1600)->count());
        config()->set('media-studio.integrations.spatie.mounts.products.dimensions_using', fn ($media) => ['width' => -1, 'height' => 1600]);
        app(SourceIndexer::class)->syncPage($principal, $workspace, 'products');
        $this->assertSame(0, DB::table('fms_entries')->where('kind', 'file')->whereNotNull('width')->count());
    }

    public function test_discovery_is_bounded_resumable_and_reuses_identity_without_copying_sources(): void
    {
        $workspace = $this->workspace();
        $principal = new Principal('user', 'member-uuid-001');
        $indexer = app(SourceIndexer::class);
        $first = $indexer->syncPage($principal, $workspace, 'products', limit: 1);
        $this->assertSame(1, $first['processed']);
        $this->assertNotNull($first['nextCursor']);
        $second = $indexer->syncPage($principal, $workspace, 'products', $first['nextCursor'], 1);
        $this->assertSame(1, $second['processed']);
        $this->assertNull($second['nextCursor']);
        $this->assertDatabaseCount('fms_entries', 3);
        $this->assertDatabaseCount('fms_asset_versions', 2);
        $this->assertSame(0, count(Storage::disk('studio-test')->allFiles()));
        $this->assertSame(4, count(Storage::disk('existing-media')->allFiles()));
        $indexer->syncPage($principal, $workspace, 'products');
        $this->assertDatabaseCount('fms_entries', 3);
        $this->assertDatabaseCount('fms_asset_versions', 2);
        $this->assertSame(2, DB::table('fms_entries')->where('kind', 'file')->distinct()->count('name'));
        $this->assertNull(DB::table('fms_asset_versions')->first()->sha256);
        $this->assertDatabaseHas('fms_entries', ['thumbnail_state' => 'ready', 'thumbnail_key' => 'existing/a-01/conversions/thumb.webp']);
    }

    public function test_tag_projection_refreshes_without_copying_sources_or_creating_versions(): void
    {
        $workspace = $this->workspace();
        $principal = new Principal('user', 'member-uuid-001');
        config(['media-studio.integrations.spatie.mounts.products.tags_using' => fn (Model $media): array => [['name' => 'Hotel '.$media->getKey(), 'locale' => 'en']]]);
        $indexer = app(SourceIndexer::class);
        $indexer->syncPage($principal, $workspace, 'products');
        $this->assertDatabaseCount('fms_entry_tags', 2);
        $this->assertSame(['png'], DB::table('fms_entries')->where('kind', 'file')->distinct()->pluck('browse_extension')->all());
        $this->getJson($this->url($workspace, 'filter-options?kind=tags'))->assertOk()->assertJsonCount(2, 'items');
        config(['media-studio.integrations.spatie.mounts.products.tags_using' => fn (Model $media): array => [['name' => 'Updated '.$media->getKey(), 'locale' => 'pl']]]);
        $indexer->syncPage($principal, $workspace, 'products');
        $this->assertDatabaseCount('fms_entry_tags', 2);
        $this->assertDatabaseCount('fms_asset_versions', 2);
        $this->getJson($this->url($workspace, 'filter-options?kind=tags&locale=en'))->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($this->url($workspace, 'filter-options?kind=tags&locale=pl'))->assertOk()->assertJsonCount(2, 'items');
    }

    public function test_source_files_are_displayed_and_streamed_but_writes_are_denied(): void
    {
        $workspace = $this->workspace();
        app(SourceIndexer::class)->syncPage(new Principal('user', 'member-uuid-001'), $workspace, 'products');
        $folder = $this->getJson($this->url($workspace))->assertOk()->json('items.0');
        $this->assertTrue($folder['readOnly']);
        $file = $this->getJson($this->url($workspace).'?parent='.$folder['id'])->assertOk()->assertJsonCount(2, 'items')->json('items.0');
        $this->assertTrue($file['readOnly']);
        $url = $this->url($workspace, 'entries/'.$file['id']);
        $this->assertSame('source-image!', $this->get($url.'/content')->assertOk()->streamedContent());
        $this->patchJson($url, ['revision' => $file['revision'], 'name' => 'No.png'])->assertForbidden();
        $this->postJson($url.'/trash', ['revision' => $file['revision']])->assertForbidden();
        $this->postJson($this->url($workspace, 'folders'), ['parent' => $folder['id'], 'name' => 'No folder'])->assertForbidden();
        $this->assertSame('photo.png', SpatieSourceFixture::findOrFail('a-01')->file_name);
    }

    public function test_fullscreen_preview_resumes_past_a_page_of_inaccessible_source_files(): void
    {
        $workspace = $this->workspace();
        app(SourceIndexer::class)->syncPage(new Principal('user', 'member-uuid-001'), $workspace, 'products');
        SpatieSourceFixture::whereIn('id', ['a-01', 'b-02'])->update(['tenant' => 'no-access']);
        $images = [];
        foreach (['a.png', 'z.png'] as $name) {
            $images[] = $this->withHeader('Idempotency-Key', 'preview-continuation-'.$name[0])
                ->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->image($name)])
                ->assertCreated()->json('id');
        }

        // Photos use capture/creation chronology, not alphabetical filenames.
        // Place the two hidden source entries between the preview anchors.
        DB::table('fms_entries')->where('public_id', $images[0])->update(['created_at' => now()->addDay()]);
        DB::table('fms_entries')->where('public_id', $images[1])->update(['created_at' => now()->subDay()]);

        foreach (['next', 'previous'] as $i => $direction) {
            $path = $this->url($workspace, 'entries/'.$images[$i].'/neighbors');
            $params = ['direction' => $direction, 'view' => 'photos', 'limit' => 2];
            $page = $this->getJson($path.'?'.http_build_query($params))->assertOk()
                ->assertJsonCount(0, 'items')->assertJsonPath('hasMore', true)->json();
            $cursor = $page[$direction.'Cursor'];
            $this->assertIsString($cursor);

            $this->getJson($path.'?'.http_build_query(['cursor' => $cursor, 'search' => $i ? 'z' : 'a', ...$params]))->assertUnprocessable();
            $this->getJson($path.'?'.http_build_query(['cursor' => $cursor, ...$params]))->assertOk()
                ->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $images[1 - $i])
                ->assertJsonPath('hasMore', false);
        }
    }

    public function test_personal_views_skip_revoked_source_entries_and_continue_in_personal_order(): void
    {
        $workspace = $this->workspace();
        app(SourceIndexer::class)->syncPage(new Principal('user', 'member-uuid-001'), $workspace, 'products');
        $projected = DB::table('fms_spatie_sources as s')->join('fms_entries as e', 's.entry_id', '=', 'e.id')->whereNotNull('s.media_key')
            ->orderBy('s.media_key')->pluck('e.public_id')->all();
        $managed = [];
        foreach (['first.txt', 'last.txt'] as $name) {
            $managed[] = $this->withHeader('Idempotency-Key', 'personal-spatie-'.sha1($name))
                ->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->createWithContent($name, 'A plain text file.')])
                ->assertCreated()->json('id');
        }
        foreach ([$managed[0], ...$projected, $managed[1]] as $id) {
            $this->travel(1)->seconds();
            $path = $this->url($workspace, 'entries/'.$id);
            $this->putJson($path.'/star', ['starred' => true])->assertOk();
            $this->postJson($path.'/visit')->assertNoContent();
        }
        SpatieSourceFixture::whereIn('id', ['a-01', 'b-02'])->update(['tenant' => 'revoked']);
        $path = $this->url($workspace, 'entries/'.$projected[0]);
        $this->putJson($path.'/star', ['starred' => false])->assertNotFound();
        $this->postJson($path.'/visit')->assertNotFound();
        $this->postJson($this->url($workspace, 'entries/status'), ['ids' => [$projected[0]]])->assertOk()->assertJsonCount(0, 'items');

        foreach (['starred', 'recent'] as $view) {
            $url = $this->url($workspace).'?view='.$view.'&limit=1';
            $first = $this->getJson($url)->assertOk()->assertJsonPath('items.0.id', $managed[1])->json();
            $hidden = $this->getJson($url.'&cursor='.urlencode($first['nextCursor']))->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('hasMore', true)->json();
            $hidden = $this->getJson($url.'&cursor='.urlencode($hidden['nextCursor']))->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('hasMore', true)->json();
            $this->getJson($url.'&cursor='.urlencode($hidden['nextCursor']))->assertOk()->assertJsonPath('items.0.id', $managed[0])->assertJsonPath('hasMore', false);

            foreach (['next' => 1, 'previous' => 0] as $direction => $index) {
                $neighborPath = $this->url($workspace, 'entries/'.$managed[$index].'/neighbors').'?view='.$view.'&direction='.$direction.'&limit=2';
                $page = $this->getJson($neighborPath)->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('hasMore', true)->json();
                $cursor = urlencode($page[$direction.'Cursor']);
                $this->getJson($neighborPath.'&cursor='.$cursor)->assertOk()->assertJsonCount(1, 'items')
                    ->assertJsonPath('items.0.id', $managed[1 - $index])->assertJsonPath('hasMore', false);
                $invalidAnchor = $this->url($workspace, 'entries/'.$managed[1 - $index].'/neighbors');
                $this->getJson($invalidAnchor.'?view='.$view.'&direction='.$direction.'&cursor='.$cursor)->assertUnprocessable();
            }
        }
    }

    public function test_source_scope_and_deletion_are_rechecked_at_read_time_without_storage_scans(): void
    {
        $workspace = $this->workspace();
        $principal = new Principal('user', 'member-uuid-001');
        app(SourceIndexer::class)->syncPage($principal, $workspace, 'products');
        $entryId = DB::table('fms_spatie_sources')->where('media_key', 'a-01')->value('entry_id');
        $entry = DB::table('fms_entries')->find($entryId);
        $this->getJson($this->url($workspace, 'entries/'.$entry->public_id))->assertOk();
        SpatieSourceFixture::whereKey('a-01')->update(['tenant' => 'not-authorized']);
        $this->getJson($this->url($workspace, 'entries/'.$entry->public_id.'/content'))->assertNotFound();
        $rows = DB::table('fms_entries')->get();
        $scope = DB::table('fms_workspaces')->first();
        DB::enableQueryLog();
        $visible = app(SourceAccess::class)->filterVisible($scope, $principal, $rows);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, $visible);
        $this->assertLessThanOrEqual(2, count($queries));
        config()->set('media-studio.integrations.spatie.mounts.products.enabled', false);
        $this->assertCount(0, app(SourceAccess::class)->filterVisible($scope, $principal, $rows));
        $this->getJson($this->url($workspace, 'entries/'.$entry->public_id.'/content'))->assertNotFound();
    }
}
