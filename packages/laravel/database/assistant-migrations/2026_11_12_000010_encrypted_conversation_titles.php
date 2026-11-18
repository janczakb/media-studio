<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_ai_messages', fn (Blueprint $t) => $t->longText('content')->change());
        Schema::table('fms_ai_events', fn (Blueprint $t) => $t->longText('data')->change());
        Schema::table('fms_ai_runs', function (Blueprint $t): void {
            $t->longText('partial_text')->nullable()->change();
            $t->longText('error')->nullable()->change();
        });
        Schema::table('fms_ai_tool_receipts', fn (Blueprint $t) => $t->longText('result')->change());
        Schema::table('fms_ai_conversations', fn (Blueprint $table) => $table->text('encrypted_title')->nullable());
        Schema::create('fms_ai_title_tokens', function (Blueprint $table): void {
            $table->char('token', 64);
            $table->ulid('conversation_id');
            $table->primary(['token', 'conversation_id']);
            $table->index('conversation_id');
            $table->foreign('conversation_id')->references('id')->on('fms_ai_conversations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_ai_title_tokens');
        Schema::table('fms_ai_conversations', fn (Blueprint $table) => $table->dropColumn('encrypted_title'));
    }
};
