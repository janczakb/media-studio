<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_workspace_defaults', function (Blueprint $table): void {
            $table->char('scope_key', 64)->primary();
            $table->foreignId('workspace_id')->nullable()->constrained('fms_workspaces')->restrictOnDelete();
        });
        Schema::create('fms_default_admissions', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->char('principal_key', 64);
            $table->primary(['workspace_id', 'principal_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_default_admissions');
        Schema::dropIfExists('fms_workspace_defaults');
    }
};
