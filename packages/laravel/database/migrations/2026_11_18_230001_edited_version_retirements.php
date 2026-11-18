<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_edited_version_retirements', function (Blueprint $table): void {
            $table->ulid('version_id')->primary();
            // Detached objects must remain retryable after the item or workspace is deleted.
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('entry_id');
            $table->json('objects')->nullable();
            $table->timestamp('due_at');
            $table->index(['due_at', 'version_id'], 'fms_edit_retirement_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_edited_version_retirements');
    }
};
