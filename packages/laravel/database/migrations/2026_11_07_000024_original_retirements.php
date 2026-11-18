<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_original_retirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('fms_entries')->cascadeOnDelete();
            $table->ulid('version_id')->unique();
            $table->string('disk');
            $table->string('object_key', 1024);
            $table->char('sha256', 64)->nullable();
            $table->timestamp('delete_after')->index();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_original_retirements');
    }
};
