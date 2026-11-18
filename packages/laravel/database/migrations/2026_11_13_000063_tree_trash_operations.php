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
            $table->ulid('tree_operation_id')->nullable();
        });
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->ulid('trash_group_id')->nullable();
            $table->index(['workspace_id', 'trash_group_id', 'id'], 'fms_trash_group');
        });
        Schema::create('fms_tree_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->string('principal_type', 64);
            $table->string('principal_id', 191);
            $table->string('principal_hash', 64);
            $table->string('request_key', 64);
            $table->string('fingerprint', 64);
            $table->string('operation', 16);
            $table->string('state', 16)->default('preparing');
            $table->text('roots');
            $table->unsignedBigInteger('discovered')->default(0);
            $table->unsignedBigInteger('examined')->default(0);
            $table->string('error', 512)->nullable();
            $table->timestamp('purging_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'principal_hash', 'request_key'], 'fms_tree_request');
            $table->index(['state', 'updated_at']);
        });
        Schema::create('fms_tree_operation_nodes', function (Blueprint $table): void {
            $table->ulid('operation_id');
            $table->unsignedBigInteger('entry_id');
            $table->unsignedBigInteger('revision');
            $table->unsignedBigInteger('after_id')->default(0);
            $table->ulid('group_id')->nullable();
            $table->boolean('expanded')->default(false);
            $table->primary(['operation_id', 'entry_id']);
            $table->foreign('operation_id')->references('id')->on('fms_tree_operations')->cascadeOnDelete();
            $table->index(['operation_id', 'expanded', 'entry_id'], 'fms_tree_frontier');
            $table->index(['entry_id', 'operation_id'], 'fms_tree_member');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_tree_operation_nodes');
        Schema::dropIfExists('fms_tree_operations');
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->dropIndex('fms_trash_group');
            $table->dropColumn('trash_group_id');
        });
        Schema::table('fms_workspaces', fn (Blueprint $table) => $table->dropColumn('tree_operation_id'));
    }
};
