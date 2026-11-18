<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_archive_jobs', function (Blueprint $table): void {
            $table->char('lease_token', 32)->nullable();
            $table->index(['state', 'updated_at'], 'fms_archive_recovery');
        });
    }

    public function down(): void
    {
        Schema::table('fms_archive_jobs', function (Blueprint $table): void {
            $table->dropIndex('fms_archive_recovery');
            $table->dropColumn('lease_token');
        });
    }
};
