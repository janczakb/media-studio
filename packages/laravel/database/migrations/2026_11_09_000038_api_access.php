<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->char('principal_key', 64);
            $table->string('principal_type', 64);
            $table->string('principal_id', 191);
            $table->char('tenant_key', 64)->nullable();
            $table->string('name', 80);
            $table->char('secret_hash', 64);
            $table->char('suffix', 4);
            $table->boolean('can_write')->default(false);
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'principal_key', 'id']);
        });
        Schema::create('fms_api_usage', function (Blueprint $table): void {
            $table->foreignId('token_id')->constrained('fms_api_tokens')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedTinyInteger('shard');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('successes')->default(0);
            $table->unsignedBigInteger('client_errors')->default(0);
            $table->unsignedBigInteger('server_errors')->default(0);
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->primary(['token_id', 'day', 'shard']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_api_usage');
        Schema::dropIfExists('fms_api_tokens');
    }
};
