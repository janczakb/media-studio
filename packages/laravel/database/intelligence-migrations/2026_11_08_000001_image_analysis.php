<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_image_analyses', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->foreignId('entry_id')->constrained('fms_entries')->cascadeOnDelete();
            $table->ulid('version_id');
            $table->string('provider', 24);
            $table->string('description', 2000);
            $table->timestamp('created_at');
            $table->index(['entry_id', 'version_id']);
        });
        Schema::create('fms_ai_labels', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('analysis_id', 64);
            $table->foreign('analysis_id')->references('id')->on('fms_image_analyses')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('normalized_name', 120);
            $table->float('score')->nullable();
            $table->index(['analysis_id', 'normalized_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_ai_labels');
        Schema::dropIfExists('fms_image_analyses');
    }
};
