<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_navigation_operations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->string('principal_hash', 64);
            $table->string('request_key', 64);
            $table->string('fingerprint', 64);
            $table->mediumText('before_payload');
            $table->mediumText('result_payload');
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->timestamp('undone_at')->nullable();
            $table->unique(['workspace_id', 'principal_hash', 'request_key'], 'fms_navigation_request');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_navigation_operations');
    }
};
