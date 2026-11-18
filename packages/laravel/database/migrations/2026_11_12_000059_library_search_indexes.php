<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->char('uploader_key', 64)->nullable();
            $table->index(['workspace_id', 'trashed_at', 'uploader_key', 'id'], 'fms_search_uploaded');
            $table->index(['workspace_id', 'trashed_at', 'normalized_name', 'id'], 'fms_search_name');
            $table->index(['workspace_id', 'trashed_at', 'kind', 'created_at', 'id'], 'fms_search_recent');
            $table->index(['workspace_id', 'trashed_at', 'created_at', 'id'], 'fms_search_newest');
        });
        Schema::table('fms_activity', fn (Blueprint $table) => $table->index(
            ['workspace_id', 'entry_public_id', 'event', 'actor_key'], 'fms_search_uploader'
        ));
    }

    public function down(): void
    {
        Schema::table('fms_activity', fn (Blueprint $table) => $table->dropIndex('fms_search_uploader'));
        Schema::table('fms_entries', function (Blueprint $table): void {
            foreach (['fms_search_name', 'fms_search_recent', 'fms_search_newest', 'fms_search_uploaded'] as $index) {
                $table->dropIndex($index);
            }
            $table->dropColumn('uploader_key');
        });
    }
};
