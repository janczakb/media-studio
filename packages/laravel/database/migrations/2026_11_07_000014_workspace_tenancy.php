<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_workspaces', function (Blueprint $table): void {
            $table->string('tenant_type', 64)->nullable();
            $table->string('tenant_id', 191)->nullable();
            $table->char('tenant_key', 64)->nullable();
            $table->index(['tenant_key', 'id'], 'fms_workspace_tenant');
        });
    }

    public function down(): void
    {
        Schema::table('fms_workspaces', function (Blueprint $table): void {
            $table->dropIndex('fms_workspace_tenant');
            $table->dropColumn(['tenant_type', 'tenant_id', 'tenant_key']);
        });
    }
};
