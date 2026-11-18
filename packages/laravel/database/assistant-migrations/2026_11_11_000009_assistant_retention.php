<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_ai_conversations', fn (Blueprint $t) => $t->index(['deleted_at', 'active_run', 'updated_at', 'id'], 'fms_ai_retention'));
    }

    public function down(): void
    {
        Schema::table('fms_ai_conversations', fn (Blueprint $t) => $t->dropIndex('fms_ai_retention'));
    }
};
