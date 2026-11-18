<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_disk_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->string('mount_key', 64);
            $table->char('path_hash', 64);
            $table->text('object_key');
            $table->char('mount_revision', 64);
            $table->unsignedBigInteger('modified_at')->nullable();
            $table->foreignId('entry_id')->unique()->constrained('fms_entries')->cascadeOnDelete();
            $table->unique(['workspace_id', 'mount_key', 'path_hash'], 'fms_disk_source_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_disk_sources');
    }
};
