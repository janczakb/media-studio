<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_personal_entries', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->char('principal_key', 64);
            $table->foreignId('entry_id')->constrained('fms_entries')->cascadeOnDelete();
            $table->timestamp('starred_at', 6)->nullable();
            $table->timestamp('viewed_at', 6)->nullable();
            $table->primary(['workspace_id', 'principal_key', 'entry_id'], 'fms_personal_identity');
            $table->index(['workspace_id', 'principal_key', 'starred_at', 'entry_id'], 'fms_personal_stars');
            $table->index(['workspace_id', 'principal_key', 'viewed_at', 'entry_id'], 'fms_personal_recents');
            $table->index('entry_id', 'fms_personal_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_personal_entries');
    }
};
