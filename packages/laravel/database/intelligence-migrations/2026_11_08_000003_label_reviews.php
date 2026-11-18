<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_ai_labels', function (Blueprint $table): void {
            $table->string('review_state', 16)->default('pending');
            $table->unsignedInteger('revision')->default(1);
            $table->string('reviewed_by', 191)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->index(['analysis_id', 'review_state', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('fms_ai_labels', function (Blueprint $table): void {
            $table->dropIndex(['analysis_id', 'review_state', 'id']);
            $table->dropColumn(['review_state', 'revision', 'reviewed_by', 'reviewed_at']);
        });
    }
};
