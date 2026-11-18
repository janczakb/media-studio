<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use UseFlex\MediaStudio\Library\FileCategories;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        $modifier = $driver === 'sqlite' ? 'virtualAs' : 'storedAs';
        $extension = match ($driver) {
            'sqlite' => "CASE WHEN kind = 'file' AND instr(name, '.') > 0 THEN lower(substr(name, length(rtrim(name, replace(name, '.', ''))) + 1)) ELSE '' END",
            'pgsql' => "CASE WHEN kind = 'file' AND position('.' in name) > 0 THEN lower(regexp_replace(name, '^.*[.]', '')) ELSE '' END",
            default => "CASE WHEN kind = 'file' AND LOCATE('.', name) > 0 THEN lower(SUBSTRING_INDEX(name, '.', -1)) ELSE '' END",
        };
        Schema::table('fms_entries', function (Blueprint $table) use ($modifier, $extension): void {
            $table->string('browse_extension', 191)->$modifier($extension);
        });
        Schema::table('fms_entries', function (Blueprint $table) use ($modifier, $extension): void {
            $table->string('browse_category', 16)->$modifier(FileCategories::sqlExpression($extension));
        });
        Schema::table('fms_entries', function (Blueprint $table): void {
            foreach (['extension', 'category'] as $key) {
                $table->index(['workspace_id', 'parent_scope', 'trashed_at', 'browse_'.$key, 'sort_group', 'normalized_name', 'id'], 'fms_browse_'.$key);
            }
        });
        Schema::create('fms_tags', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->string('locale', 16);
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->unique(['workspace_id', 'locale', 'normalized_name'], 'fms_tag_name');
            $table->index(['workspace_id', 'normalized_name', 'id'], 'fms_tag_search');
        });
        Schema::create('fms_entry_tags', function (Blueprint $table): void {
            $table->foreignId('entry_id')->constrained('fms_entries')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('fms_tags')->cascadeOnDelete();
            $table->primary(['entry_id', 'tag_id']);
            $table->index(['tag_id', 'entry_id'], 'fms_tag_entries');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_entry_tags');
        Schema::dropIfExists('fms_tags');
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->dropIndex('fms_browse_extension');
            $table->dropIndex('fms_browse_category');
        });
        Schema::table('fms_entries', fn (Blueprint $table) => $table->dropColumn('browse_category'));
        Schema::table('fms_entries', fn (Blueprint $table) => $table->dropColumn('browse_extension'));
    }
};
