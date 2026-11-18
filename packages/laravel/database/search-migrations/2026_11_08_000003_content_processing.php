<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_search_content', function (Blueprint $table): void {
            $table->char('source_profile', 64)->default('');
        });
        Schema::create('fms_content_jobs', function (Blueprint $table): void {
            $table->ulid('version_id')->primary();
            $table->foreignId('entry_id')->constrained('fms_entries')->cascadeOnDelete();
            $table->string('state', 24)->default('queued');
            $table->timestamps();
            $table->index(['entry_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_content_jobs');
        Schema::table('fms_search_content', fn (Blueprint $table) => $table->dropColumn('source_profile'));
    }
};
