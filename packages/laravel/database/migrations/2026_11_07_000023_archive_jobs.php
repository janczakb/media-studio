<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_archive_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->string('principal_type', 64);
            $table->string('principal_id', 191);
            $table->char('principal_key', 64);
            $table->json('entries');
            $table->string('state', 16);
            $table->string('disk');
            $table->text('object_key')->nullable();
            $table->char('fingerprint', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['state', 'expires_at']);
            $table->index(['workspace_id', 'principal_key', 'created_at'], 'fms_archive_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_archive_jobs');
    }
};
