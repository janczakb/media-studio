<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_extension_requests', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->char('challenge', 64);
            $table->char('code_hash', 64);
            $table->string('name', 80);
            $table->string('principal_type', 64)->nullable();
            $table->string('principal_id', 191)->nullable();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('fms_extension_tokens', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->char('secret_hash', 64);
            $table->string('principal_type', 64);
            $table->string('principal_id', 191);
            $table->char('principal_key', 64);
            $table->unsignedBigInteger('workspace_id');
            $table->string('name', 80);
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'principal_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_extension_tokens');
        Schema::dropIfExists('fms_extension_requests');
    }
};
