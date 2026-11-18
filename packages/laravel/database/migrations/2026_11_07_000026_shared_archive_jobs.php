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
            $table->foreignId('share_id')->nullable()->constrained('fms_shares')->cascadeOnDelete();
            $table->unsignedInteger('share_revision')->nullable();
            $table->index(['share_id', 'state', 'expires_at'], 'fms_shared_archive_jobs');
        });
    }

    public function down(): void
    {
        Schema::table('fms_archive_jobs', function (Blueprint $table): void {
            $table->dropIndex('fms_shared_archive_jobs');
            $table->dropConstrainedForeignId('share_id');
            $table->dropColumn('share_revision');
        });
    }
};
