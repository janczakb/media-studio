<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MigrationRollbackTest extends TestCase
{
    public function test_library_migrations_can_roll_back_and_reapply_without_dropping_host_tables(): void
    {
        $migrations = glob(__DIR__.'/../packages/laravel/database/migrations/*.php');
        foreach (array_reverse($migrations) as $path) {
            (require $path)->down();
        }
        $this->assertFalse(Schema::hasTable('fms_workspaces'));
        foreach ($migrations as $path) {
            (require $path)->up();
        }
        $this->assertTrue(Schema::hasColumn('fms_entries', 'revision'));
        $this->assertTrue(Schema::hasColumn('fms_entries', 'thumbnail_state'));
        $workspace = $this->workspace();
        $this->getJson($this->url($workspace))->assertOk()->assertJsonCount(0, 'items');
        $this->assertSame(1, DB::table('fms_memberships')->count());
    }
}
