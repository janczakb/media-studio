<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Sources\DiskIndexer;
use UseFlex\MediaStudio\Sources\DiskSourceAccess;

final class DiskSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('external');
        Storage::disk('external')->put('legacy/campaign/readme.txt', 'existing document');
        config()->set('media-studio.sources.mounts.archive', ['disk' => 'external', 'prefix' => 'legacy', 'label' => 'Archive', 'authorize' => fn ($workspace, $principal) => $principal->id === 'member-uuid-001']);
    }

    public function test_import_is_read_only_idempotent_and_preserves_original_bytes(): void
    {
        $workspace = $this->workspace();
        $principal = new Principal('user', 'member-uuid-001');
        $indexer = app(DiskIndexer::class);
        $paths = ['legacy/campaign/readme.txt'];
        $indexer->importPage($principal, $workspace, 'archive', $paths);
        $this->assertSame(0, DB::table('fms_disk_sources')->count());
        $indexer->importPage($principal, $workspace, 'archive', $paths, true);
        $indexer->importPage($principal, $workspace, 'archive', $paths, true);
        $this->assertSame(3, DB::table('fms_disk_sources')->count());
        $this->assertSame(1, DB::table('fms_asset_versions')->count());
        $this->assertSame('existing document', Storage::disk('external')->get($paths[0]));
        $root = $this->getJson($this->url($workspace))->assertOk()->json('items.0');
        $this->assertTrue($root['readOnly']);
        $folder = $this->getJson($this->url($workspace).'?parent='.$root['id'])->assertOk()->json('items.0');
        $file = $this->getJson($this->url($workspace).'?parent='.$folder['id'])->assertOk()->json('items.0');
        $this->assertSame('readme.txt', $file['name']);
        $this->assertTrue($file['readOnly']);
        $this->get($this->url($workspace, 'entries/'.$file['id'].'/content').'?v='.$file['versionId'])->assertOk();
        $this->patchJson($this->url($workspace, 'entries/'.$file['id']), ['name' => 'changed.txt', 'revision' => $file['revision']])->assertForbidden();
        config()->set('media-studio.sources.mounts.archive.authorize', fn () => false);
        $this->assertSame([], $this->getJson($this->url($workspace))->assertOk()->json('items'));
        $this->get($this->url($workspace, 'entries/'.$file['id'].'/content').'?v='.$file['versionId'])->assertNotFound();
    }

    public function test_changed_source_is_not_served_as_previous_version(): void
    {
        $workspace = $this->workspace();
        $principal = new Principal('user', 'member-uuid-001');
        app(DiskIndexer::class)->importPage($principal, $workspace, 'archive', ['legacy/campaign/readme.txt'], true);
        $row = DB::table('fms_entries as e')->join('fms_asset_versions as v', 'v.id', '=', 'e.current_version_id')->where('e.kind', 'file')->first(['e.*', 'v.disk', 'v.object_key']);
        $ws = DB::table('fms_workspaces')->where('public_id', $workspace)->first();
        app(DiskSourceAccess::class)->original($ws, $principal, $row);
        Storage::disk('external')->put('legacy/campaign/readme.txt', 'changed source with different bytes');
        $this->expectException(HttpException::class);
        app(DiskSourceAccess::class)->original($ws, $principal, $row);
    }

    public function test_inventory_and_cursor_resume_are_bounded_and_idempotent(): void
    {
        $workspace = $this->workspace();
        Storage::disk('external')->put('legacy/second.txt', 'second');
        $path = sys_get_temp_dir().'/fms-inventory-'.bin2hex(random_bytes(8)).'.jsonl';
        $arguments = ['workspace' => $workspace, 'mount' => 'archive', 'principal-type' => 'user', 'principal-id' => 'member-uuid-001'];
        try {
            $this->assertSame(0, Artisan::call('media-studio:sources:inventory', [...$arguments, 'output' => $path]));
            $this->assertCount(2, file($path));
            $this->assertSame(0, Artisan::call('media-studio:sources:import', [...$arguments, 'manifest' => $path, '--limit' => 1, '--apply' => true]));
            $first = json_decode(trim(Artisan::output()), true, 8, JSON_THROW_ON_ERROR);
            $this->assertNotNull($first['nextCursor']);
            $this->assertSame(1, DB::table('fms_asset_versions')->count());
            $this->assertSame(0, Artisan::call('media-studio:sources:import', [...$arguments, 'manifest' => $path, '--limit' => 1, '--apply' => true, '--cursor' => $first['nextCursor']]));
            $second = json_decode(trim(Artisan::output()), true, 8, JSON_THROW_ON_ERROR);
            $this->assertNull($second['nextCursor']);
            $this->assertSame(2, DB::table('fms_asset_versions')->count());
            $this->assertSame(0, Artisan::call('media-studio:sources:import', [...$arguments, 'manifest' => $path, '--limit' => 1, '--apply' => true, '--cursor' => $first['nextCursor']]));
            $this->assertSame(2, DB::table('fms_asset_versions')->count());
            $this->assertSame(2, Artisan::call('media-studio:sources:import', [...$arguments, 'manifest' => $path, '--cursor' => $first['nextCursor']]));
            $this->assertSame(2, Artisan::call('media-studio:sources:inventory', [...$arguments, 'output' => $path]));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_source_revision_blocks_old_projection_and_reimport_restores_folders(): void
    {
        $workspace = $this->workspace();
        $principal = new Principal('user', 'member-uuid-001');
        $indexer = app(DiskIndexer::class);
        $indexer->importPage($principal, $workspace, 'archive', ['legacy/campaign/readme.txt'], true);
        config()->set('media-studio.sources.mounts.archive.revision', 2);
        $this->assertSame([], $this->getJson($this->url($workspace))->assertOk()->json('items'));
        $indexer->importPage($principal, $workspace, 'archive', ['legacy/campaign/readme.txt'], true);
        $this->assertCount(1, $this->getJson($this->url($workspace))->assertOk()->json('items'));
        $this->assertSame(3, DB::table('fms_disk_sources')->count());
        $this->assertSame(2, DB::table('fms_asset_versions')->count());
    }

    public function test_import_requires_source_sync_permission_even_for_owner(): void
    {
        $workspace = $this->workspace();
        Gate::define('studio.sources.sync', static fn () => false);
        try {
            app(DiskIndexer::class)->importPage(new Principal('user', 'member-uuid-001'), $workspace, 'archive', ['legacy/campaign/readme.txt'], true);
            $this->fail('Source sync permission bypassed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, DB::table('fms_disk_sources')->count());
    }

    public function test_prefix_traversal_is_rejected_without_indexing(): void
    {
        $workspace = $this->workspace();
        try {
            app(DiskIndexer::class)->importPage(new Principal('user', 'member-uuid-001'), $workspace, 'archive', ['legacy/../secret.txt'], true);
            $this->fail('Traversal accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(0, DB::table('fms_disk_sources')->count());
    }
}
