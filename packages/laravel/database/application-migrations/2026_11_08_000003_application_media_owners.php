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
            $table->index(['tenant_scope', 'source_key', 'remote_id'], 'fms_application_owner');
        });
    }

    public function down(): void
    {
        Schema::table('fms_application_entries', function (Blueprint $table): void {
            $table->dropIndex('fms_application_owner');
        });
    }
};
