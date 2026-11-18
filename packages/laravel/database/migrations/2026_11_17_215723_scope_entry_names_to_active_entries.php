<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'fms_entries';

    private const COLUMNS = ['workspace_id', 'parent_scope', 'kind', 'normalized_name'];

    private const LEGACY_INDEX = 'fms_entry_name';

    private const ACTIVE_INDEX = 'fms_active_entry_name';

    public function up(): void
    {
        if (! Schema::hasIndex(self::TABLE, self::ACTIVE_INDEX)) {
            if ($this->usesGeneratedColumn()) {
                if (! Schema::hasColumn(self::TABLE, 'active_name_scope')) {
                    Schema::table(self::TABLE, function (Blueprint $table): void {
                        $table->unsignedTinyInteger('active_name_scope')->nullable()
                            ->virtualAs('CASE WHEN trashed_at IS NULL THEN 1 ELSE NULL END');
                    });
                }
                Schema::table(self::TABLE, function (Blueprint $table): void {
                    $table->unique([...self::COLUMNS, 'active_name_scope'], self::ACTIVE_INDEX);
                });
            } else {
                $grammar = DB::connection()->getQueryGrammar();
                $table = $grammar->wrapTable(self::TABLE);
                $index = $grammar->wrap(self::ACTIVE_INDEX);
                $columns = $grammar->columnize(self::COLUMNS);
                DB::statement("CREATE UNIQUE INDEX {$index} ON {$table} ({$columns}) WHERE trashed_at IS NULL");
            }
        }

        if (Schema::hasIndex(self::TABLE, self::LEGACY_INDEX)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropUnique(self::LEGACY_INDEX));
        }
    }

    public function down(): void
    {
        // Recreate the legacy constraint first. If names have been reused, the database rejects it without removing active uniqueness.
        if (! Schema::hasIndex(self::TABLE, self::LEGACY_INDEX)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->unique(self::COLUMNS, self::LEGACY_INDEX));
        }

        if ($this->usesGeneratedColumn()) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::ACTIVE_INDEX);
                $table->dropColumn('active_name_scope');
            });

            return;
        }

        // On PostgreSQL a filtered index is an index, not a table constraint.
        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable(self::TABLE);
        $index = $grammar->wrap(self::ACTIVE_INDEX);
        DB::statement(DB::getDriverName() === 'sqlsrv' ? "DROP INDEX {$index} ON {$table}" : "DROP INDEX {$index}");
    }

    private function usesGeneratedColumn(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
