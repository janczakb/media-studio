<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use UseFlex\MediaStudio\Library\FileCategories;

/** Earlier SQLite table rebuilds could turn these virtual columns into ordinary nullable columns. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        $columns = collect(Schema::getColumns('fms_entries'))->keyBy('name');
        if (($columns['browse_extension']['generation'] ?? null) !== null && ($columns['browse_category']['generation'] ?? null) !== null) {
            return;
        }
        $grammar = DB::connection()->getQueryGrammar();
        $name = DB::connection()->getTablePrefix().'fms_entries';
        $table = $grammar->wrapTable('fms_entries');
        $indexes = [];
        foreach (Schema::getIndexes('fms_entries') as $index) {
            if (array_intersect($index['columns'], ['browse_extension', 'browse_category']) === []) {
                continue;
            }
            $sql = DB::table('sqlite_master')->where('type', 'index')->where('tbl_name', $name)->where('name', $index['name'])->value('sql');
            if (! is_string($sql)) {
                throw new RuntimeException('Cannot preserve the browse index definition.');
            }
            $indexes[] = $sql;
            DB::statement('DROP INDEX '.$grammar->wrap($index['name']));
        }
        // Native SQLite DDL preserves the other generated columns and source data.
        DB::statement("ALTER TABLE {$table} DROP COLUMN browse_category");
        DB::statement("ALTER TABLE {$table} DROP COLUMN browse_extension");
        $extension = "CASE WHEN kind = 'file' AND instr(name, '.') > 0 THEN lower(substr(name, length(rtrim(name, replace(name, '.', ''))) + 1)) ELSE '' END";
        $category = FileCategories::sqlExpression($extension);
        DB::statement("ALTER TABLE {$table} ADD COLUMN browse_extension VARCHAR(191) GENERATED ALWAYS AS ({$extension}) VIRTUAL");
        DB::statement("ALTER TABLE {$table} ADD COLUMN browse_category VARCHAR(16) GENERATED ALWAYS AS ({$category}) VIRTUAL");
        foreach ($indexes as $sql) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        // A rollback must not deliberately corrupt working generated columns.
    }
};
