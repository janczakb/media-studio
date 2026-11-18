<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_analysis_jobs', function (Blueprint $table): void {
            $table->ulid('version_id')->primary();
            $table->foreignId('entry_id')->constrained('fms_entries')->cascadeOnDelete();
            $table->string('state', 24)->default('queued');
            $table->timestamps();
            $table->index(['entry_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_analysis_jobs');
    }
};
