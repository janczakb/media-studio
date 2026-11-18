<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class SourceImportsTest extends TestCase
{
    private string $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = tempnam(sys_get_temp_dir(), 'fms-source-run-');
        Storage::fake('source-run');
        $lines = [];
        for ($i = 0; $i < 61; $i++) {
            $path = 'archive/file-'.$i.'.txt';
            Storage::disk('source-run')->put($path, 'record '.$i);
            $lines[] = json_encode(['path' => $path]);
        }
        file_put_contents($this->inventory, implode("\n", $lines)."\n");
        config()->set('media-studio.sources.mounts.archive', ['disk' => 'source-run', 'prefix' => 'archive', 'inventory' => $this->inventory, 'authorize' => fn () => true]);
    }

    protected function tearDown(): void
    {
        unlink($this->inventory);
        parent::tearDown();
    }

    public function test_import_checkpoints_survive_reload_pause_resume_and_duplicate_requests(): void
    {
        $w = $this->workspace();
        $run = $this->postJson($this->url($w, 'source-imports'), ['mount' => 'archive'])->assertCreated()->json();
        $this->postJson($this->url($w, 'source-imports'), ['mount' => 'archive'])->assertCreated()->assertJsonPath('id', $run['id']);
        $path = $this->url($w, 'source-imports/'.$run['id']);
        $first = $this->postJson($path.'/step', ['revision' => 0])->assertOk()->assertJsonPath('processed', 60)->assertJsonPath('state', 'ready')->json();
        $this->postJson($path.'/step', ['revision' => 0])->assertConflict();
        $paused = $this->patchJson($path, ['revision' => $first['revision'], 'action' => 'pause'])->assertOk()->assertJsonPath('state', 'paused')->json();
        $this->getJson($this->url($w, 'source-imports'))->assertOk()->assertJsonPath('items.0.state', 'paused')->assertJsonPath('items.0.processed', 60);
        $this->postJson($path.'/step', ['revision' => $paused['revision']])->assertConflict();
        $ready = $this->patchJson($path, ['revision' => $paused['revision'], 'action' => 'resume'])->assertOk()->json();
        $done = $this->postJson($path.'/step', ['revision' => $ready['revision']])->assertOk()->assertJsonPath('state', 'completed')->assertJsonPath('processed', 61)->assertJsonPath('progress', 100)->json();
        $this->postJson($path.'/step', ['revision' => $done['revision']])->assertConflict();
        $this->assertSame(61, DB::table('fms_asset_versions')->count());
        $this->assertSame(61, count(Storage::disk('source-run')->allFiles()));
    }

    public function test_an_invalidated_batch_cannot_publish_files_or_advance_its_checkpoint(): void
    {
        $w = $this->workspace();
        $run = $this->postJson($this->url($w, 'source-imports'), ['mount' => 'archive'])->assertCreated()->json();
        config()->set('media-studio.sources.mounts.archive.authorize', function () use ($run) {
            DB::table('fms_source_imports')->where('id', $run['id'])->whereNotNull('lease_token')->update(['state' => 'cancelled', 'lease_token' => null, 'lease_until' => null]);

            return true;
        });
        $this->postJson($this->url($w, 'source-imports/'.$run['id'].'/step'), ['revision' => 0])->assertConflict();
        $this->assertSame(0, DB::table('fms_asset_versions')->count());
        $this->assertSame(0, DB::table('fms_source_imports')->value('byte_offset'));
        $this->assertSame('cancelled', DB::table('fms_source_imports')->value('state'));
    }

    public function test_permission_revocation_and_inventory_changes_are_rechecked(): void
    {
        $w = $this->workspace();
        $run = $this->postJson($this->url($w, 'source-imports'), ['mount' => 'archive'])->assertCreated()->json();
        file_put_contents($this->inventory, "{\"path\":\"archive/file-0.txt\"}\n");
        $this->postJson($this->url($w, 'source-imports/'.$run['id'].'/step'), ['revision' => 0])->assertConflict();
        $this->assertSame('failed', DB::table('fms_source_imports')->value('state'));
        $this->assertSame(0, DB::table('fms_asset_versions')->count());
        Gate::define('studio.sources.sync', fn () => false);
        $this->getJson($this->url($w, 'source-imports'))->assertForbidden();
        $this->patchJson($this->url($w, 'source-imports/'.$run['id']), ['revision' => 1, 'action' => 'resume'])->assertForbidden();
    }

    public function test_foreign_workspace_cannot_address_an_import(): void
    {
        $w = $this->workspace();
        $other = $this->workspace('Other');
        $run = $this->postJson($this->url($w, 'source-imports'), ['mount' => 'archive'])->assertCreated()->json();
        $this->postJson($this->url($other, 'source-imports/'.$run['id'].'/step'), ['revision' => 0])->assertNotFound();
        $this->patchJson($this->url($other, 'source-imports/'.$run['id']), ['revision' => 0, 'action' => 'cancel'])->assertNotFound();
    }
}
