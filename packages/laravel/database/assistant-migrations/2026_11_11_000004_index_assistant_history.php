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
            $t->string('search_title', 160)->default('');
            $t->index(['workspace_id', 'principal_key', 'search_title', 'id'], 'fms_ai_title_search');
            $t->index(['search_title', 'id'], 'fms_ai_title_backfill');
        });
    }

    public function down(): void
    {
        Schema::table('fms_ai_conversations', function (Blueprint $t): void {
            $t->dropIndex('fms_ai_title_search');
            $t->dropIndex('fms_ai_title_backfill');
            $t->dropColumn('search_title');
        });
    }
};
