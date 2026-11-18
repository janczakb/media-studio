<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_upload_receipts', function (Blueprint $table): void {
            $table->ulid('current_attempt_id')->nullable();
        });
        Schema::create('fms_upload_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('receipt_id')->nullable()->constrained('fms_upload_receipts')->nullOnDelete();
            $table->ulid('workspace_public_id');
            $table->string('storage_root', 1024);
            $table->string('disk');
            $table->string('object_key', 1024);
            $table->char('storage_target', 64);
            $table->string('state', 24);
            $table->char('lease_token', 64);
            $table->timestamp('lease_expires_at');
            $table->timestamp('writer_finished_at')->nullable();
            $table->timestamp('next_cleanup_at')->nullable();
            $table->unsignedInteger('cleanup_attempts')->default(0);
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamps();
            $table->index(['next_cleanup_at', 'id'], 'fms_attempt_cleanup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_upload_attempts');
        Schema::table('fms_upload_receipts', function (Blueprint $table): void {
            $table->dropColumn('current_attempt_id');
        });
    }
};
