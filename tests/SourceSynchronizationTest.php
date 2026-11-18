<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Sources\DiskIndexer;

final class SourceSynchronizationTest extends TestCase
{
    private function source(): string
    {
        Storage::fake('external');
        Storage::disk('external')->put('legacy/file.txt', 'original');
        config()->set('media-studio.sources.mounts.archive', ['disk' => 'external', 'prefix' => 'legacy', 'authorize' => fn () => true]);
        $workspace = $this->workspace();
        app(DiskIndexer::class)->importPage(new Principal('user', 'member-uuid-001'), $workspace, 'archive', ['legacy/file.txt'], true);
        config()->set('media-studio.sources.sync.targets.archive', ['kind' => 'disk', 'workspace' => $workspace, 'mount' => 'archive', 'principal_type' => 'user', 'principal_id' => 'member-uuid-001', 'interval_seconds' => 1, 'missing_grace_seconds' => 300]);

        return $workspace;
    }

    private function cycle(): void
    {
        $this->assertSame(0, Artisan::call('media-studio:sources:sync', ['target' => 'archive']));
    }

    public function test_changes_create_one_version_and_unchanged_cycles_are_idempotent(): void
    {
        $this->source();
        $this->cycle();
        $this->assertSame(1, DB::table('fms_asset_versions')->count());
        Storage::disk('external')->put('legacy/file.txt', 'changed and longer content');
        $this->travel(2)->seconds();
        $this->cycle();
        $this->assertSame(2, DB::table('fms_asset_versions')->count());
        $this->travel(2)->seconds();
        $this->cycle();
        $this->assertSame(2, DB::table('fms_asset_versions')->count());
        $this->assertSame('changed and longer content', Storage::disk('external')->get('legacy/file.txt'));
    }

    public function test_missing_files_require_confirmation_and_are_restored_without_losing_identity(): void
    {
        $this->source();
        $entry = DB::table('fms_entries')->where('kind', 'file')->first();
        Storage::disk('external')->delete('legacy/file.txt');
        $this->cycle();
        $this->assertNull(DB::table('fms_disk_sources')->where('entry_id', $entry->id)->value('missing_at'));
        $this->travel(301)->seconds();
        $this->cycle();
        $this->assertNotNull(DB::table('fms_disk_sources')->where('entry_id', $entry->id)->value('missing_at'));
        $this->assertSame(1, DB::table('fms_asset_versions')->count());
        Storage::disk('external')->put('legacy/file.txt', 'original');
        $this->travel(2)->seconds();
        $this->cycle();
        $this->assertNull(DB::table('fms_disk_sources')->where('entry_id', $entry->id)->value('missing_at'));
        $this->assertSame($entry->id, DB::table('fms_entries')->where('kind', 'file')->value('id'));
    }

    public function test_revoked_access_cannot_be_interpreted_as_deletion(): void
    {
        $this->source();
        Gate::define('studio.sources.sync', fn () => false);
        $this->assertSame(1, Artisan::call('media-studio:sources:sync', ['target' => 'archive']));
        $this->assertSame(0, DB::table('fms_disk_sources')->sum('missing_checks'));
        $this->assertSame(0, DB::table('fms_source_syncs')->count());
    }

    public function test_page_budget_and_interval_are_enforced(): void
    {
        $this->source();
        $this->assertSame(0, Artisan::call('media-studio:sources:sync', ['target' => 'archive', '--limit' => 1, '--pages' => 1]));
        $this->assertSame('check', DB::table('fms_source_syncs')->value('phase'));
        $this->cycle();
        $completed = DB::table('fms_source_syncs')->value('completed_at');
        $this->cycle();
        $this->assertSame($completed, DB::table('fms_source_syncs')->value('completed_at'));
        $this->assertStringContainsString('waiting', Artisan::output());
        $this->assertSame(2, Artisan::call('media-studio:sources:sync', ['--limit' => 121]));
    }
}
