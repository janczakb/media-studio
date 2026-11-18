<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_application_entries', function (Blueprint $table): void {
            $table->index(['tenant_scope', 'parent_key', 'sort_name', 'id'], 'fms_application_combined_browse');
        });
    }

    public function down(): void
    {
        Schema::table('fms_application_entries', function (Blueprint $table): void {
            $table->dropIndex('fms_application_combined_browse');
        });
    }
};
