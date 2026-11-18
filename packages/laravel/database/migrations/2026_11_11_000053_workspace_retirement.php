<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_workspaces', fn (Blueprint $t) => $t->ulid('retirement_id')->nullable()->index());
        Schema::create('fms_workspace_operations', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('workspace', 26);
            $t->unsignedBigInteger('source_id');
            $t->string('name', 160);
            $t->string('mode', 12);
            $t->string('target', 26)->nullable();
            $t->unsignedBigInteger('folder_id')->nullable();
            $t->string('principal_type', 64);
            $t->string('principal_id', 191);
            $t->char('principal_key', 64);
            $t->string('tenant_type', 64)->nullable();
            $t->string('tenant_id', 191)->nullable();
            $t->string('state', 16);
            $t->string('phase', 16)->default('entries');
            $t->unsignedBigInteger('processed')->default(0);
            $t->char('lease', 32)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->string('error', 160)->nullable();
            $t->timestamp('dispatched_at')->nullable();
            $t->timestamps();
            $t->index(['state', 'lease_until', 'id'], 'fms_workspace_work');
            $t->index(['principal_key', 'id']);
        });
        Schema::create('fms_workspace_objects', function (Blueprint $t): void {
            $t->id();
            $t->ulid('operation');
            $t->unsignedBigInteger('entry_id')->nullable();
            $t->string('source_table', 32);
            $t->string('source_id', 64);
            $t->string('disk');
            $t->text('object_key');
            $t->string('target_disk')->nullable();
            $t->text('target_key')->nullable();
            $t->char('identity', 64)->unique();
            $t->timestamp('copied_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->index(['operation', 'entry_id', 'id']);
            $t->index(['operation', 'deleted_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_workspace_objects');
        Schema::dropIfExists('fms_workspace_operations');
        Schema::table('fms_workspaces', fn (Blueprint $t) => $t->dropIndex(['retirement_id']));
        Schema::table('fms_workspaces', fn (Blueprint $t) => $t->dropColumn('retirement_id'));
    }
};
