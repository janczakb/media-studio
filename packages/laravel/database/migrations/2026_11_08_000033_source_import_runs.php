<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_source_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->string('mount_key', 64);
            $table->string('principal_type', 64);
            $table->string('principal_id', 191);
            $table->char('inventory_identity', 64);
            $table->char('mount_revision', 64);
            $table->unsignedBigInteger('byte_offset')->default(0);
            $table->unsignedBigInteger('inventory_bytes');
            $table->unsignedBigInteger('processed')->default(0);
            $table->string('state', 24)->default('ready');
            $table->unsignedInteger('revision')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at', 'id'], 'fms_source_import_page');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_source_imports');
    }
};
