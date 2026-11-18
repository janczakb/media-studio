<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_ai_attachments', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $t->string('principal_key', 64);
            $t->ulid('run_id')->nullable();
            $t->ulid('conversation_id')->nullable();
            $t->string('state', 16);
            $t->string('name', 191);
            $t->string('mime', 100);
            $t->unsignedInteger('bytes');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['workspace_id', 'principal_key', 'state', 'run_id'], 'fms_ai_attachment_scope');
            $t->index(['state', 'expires_at', 'id'], 'fms_ai_attachment_expiry');
            $t->index(['conversation_id', 'id'], 'fms_ai_attachment_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_ai_attachments');
    }
};
