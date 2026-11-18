<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_ai_conversations', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $t->string('principal_key', 64);
            $t->string('title', 160);
            $t->ulid('active_run')->nullable();
            $t->unsignedInteger('context_bytes')->default(0);
            $t->unsignedInteger('message_count')->default(0);
            $t->timestamps();
            $t->index(['workspace_id', 'principal_key', 'id'], 'fms_ai_history');
        });
        Schema::create('fms_ai_runs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('conversation_id');
            $t->foreign('conversation_id')->references('id')->on('fms_ai_conversations')->cascadeOnDelete();
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->string('state', 24);
            $t->string('model_key', 80);
            $t->string('provider', 80);
            $t->string('model', 160);
            $t->json('context');
            $t->json('usage')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
            $t->unique(['conversation_id', 'request_key'], 'fms_ai_idempotency');
            $t->index(['state', 'updated_at']);
        });
        Schema::create('fms_ai_messages', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('conversation_id');
            $t->foreign('conversation_id')->references('id')->on('fms_ai_conversations')->cascadeOnDelete();
            $t->ulid('run_id');
            $t->string('role', 16);
            $t->text('content');
            $t->timestamps();
            $t->index(['conversation_id', 'id'], 'fms_ai_messages_page');
            $t->unique(['run_id', 'role']);
        });
        Schema::create('fms_ai_tool_receipts', function (Blueprint $t): void {
            $t->ulid('run_id');
            $t->foreign('run_id')->references('id')->on('fms_ai_runs')->cascadeOnDelete();
            $t->string('call_id', 191);
            $t->string('input_hash', 64);
            $t->text('result');
            $t->timestamp('created_at');
            $t->primary(['run_id', 'call_id']);
        });
        Schema::create('fms_ai_events', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->ulid('run_id');
            $t->foreign('run_id')->references('id')->on('fms_ai_runs')->cascadeOnDelete();
            $t->string('type', 24);
            $t->text('data');
            $t->timestamp('created_at');
            $t->index(['run_id', 'id']);
            $t->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['fms_ai_tool_receipts', 'fms_ai_events', 'fms_ai_messages', 'fms_ai_runs', 'fms_ai_conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
