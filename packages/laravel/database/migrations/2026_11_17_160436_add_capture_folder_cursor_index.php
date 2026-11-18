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
            $table->index(['workspace_id', 'parent_scope', 'kind', 'trashed_at', 'public_id'], 'fms_capture_folders');
        });
    }

    public function down(): void
    {
        Schema::table('fms_entries', fn (Blueprint $table) => $table->dropIndex('fms_capture_folders'));
    }
};
