<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_memberships', function (Blueprint $table): void {
            $table->char('principal_key', 64)->nullable();
        });
        DB::table('fms_memberships')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('fms_memberships')->where('id', $row->id)->update(['principal_key' => hash('sha256', json_encode([$row->principal_type, $row->principal_id], JSON_THROW_ON_ERROR))]);
            }
        });
        Schema::table('fms_memberships', function (Blueprint $table): void {
            $table->dropUnique('fms_member_identity');
            $table->dropIndex('fms_member_workspaces');
            $table->unique(['workspace_id', 'principal_key'], 'fms_member_identity');
            $table->index(['principal_key', 'workspace_id'], 'fms_member_workspaces');
        });
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamp('trashed_at')->nullable();
            $table->index(['workspace_id', 'trashed_at', 'parent_scope', 'sort_group', 'normalized_name', 'id'], 'fms_active_browse');
            $table->index(['workspace_id', 'media_type', 'trashed_at', 'sort_group', 'normalized_name', 'id'], 'fms_active_photos');
        });
    }

    public function down(): void
    {
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->dropIndex('fms_active_browse');
            $table->dropIndex('fms_active_photos');
            $table->dropColumn(['revision', 'metadata', 'trashed_at']);
        });
        Schema::table('fms_memberships', function (Blueprint $table): void {
            $table->dropUnique('fms_member_identity');
            $table->dropIndex('fms_member_workspaces');
            $table->dropColumn('principal_key');
            $table->unique(['workspace_id', 'principal_type', 'principal_id'], 'fms_member_identity');
            $table->index(['principal_type', 'principal_id', 'workspace_id'], 'fms_member_workspaces');
        });
    }
};
