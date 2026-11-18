<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_ai_runs', fn (Blueprint $table) => $table->index(['conversation_id', 'id'], 'fms_ai_latest_run'));
        Schema::table('fms_ai_attachments', fn (Blueprint $table) => $table->index(['conversation_id', 'principal_key', 'run_id'], 'fms_ai_attachment_page'));
    }

    public function down(): void
    {
        Schema::table('fms_ai_attachments', fn (Blueprint $table) => $table->dropIndex('fms_ai_attachment_page'));
        Schema::table('fms_ai_runs', fn (Blueprint $table) => $table->dropIndex('fms_ai_latest_run'));
    }
};
