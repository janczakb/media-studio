<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_ai_conversations', function (Blueprint $t): void {
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('deleted_at')->nullable();
            $t->index(['workspace_id', 'principal_key', 'deleted_at', 'id'], 'fms_ai_active_history');
        });
    }

    public function down(): void
    {
        Schema::table('fms_ai_conversations', function (Blueprint $t): void {
            $t->dropIndex('fms_ai_active_history');
            $t->dropColumn(['revision', 'deleted_at']);
        });
    }
};
