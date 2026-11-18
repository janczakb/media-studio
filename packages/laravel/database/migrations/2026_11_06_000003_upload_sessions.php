<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_upload_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->char('principal_key', 64);
            $table->char('key_hash', 64);
            $table->char('fingerprint', 64);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 191);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('chunk_bytes');
            $table->unsignedInteger('part_count');
            $table->string('state', 24);
            $table->char('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->foreignId('entry_id')->nullable()->constrained('fms_entries')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'principal_key', 'key_hash'], 'fms_session_retry');
            $table->index(['workspace_id', 'principal_key', 'state'], 'fms_session_admission');
            $table->index(['cleaned_at', 'expires_at', 'id'], 'fms_session_cleanup');
        });
        Schema::create('fms_upload_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('fms_upload_sessions')->cascadeOnDelete();
            $table->unsignedInteger('part_index');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unique(['session_id', 'part_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_upload_parts');
        Schema::dropIfExists('fms_upload_sessions');
    }
};
