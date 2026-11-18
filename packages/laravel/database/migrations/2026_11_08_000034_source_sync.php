<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['fms_disk_sources', 'fms_spatie_sources'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->timestamp('missing_since')->nullable();
                $table->unsignedInteger('missing_checks')->default(0);
                $table->timestamp('missing_at')->nullable();
                $table->index(['workspace_id', 'mount_key', 'id'], $name.'_sync_page');
            });
        }
        Schema::create('fms_source_syncs', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->char('revision', 64);
            $table->unsignedBigInteger('after_id')->default(0);
            $table->text('discovery_cursor')->nullable();
            $table->string('phase', 16)->default('check');
            $table->char('lease', 26)->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_source_syncs');
        foreach (['fms_disk_sources', 'fms_spatie_sources'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_sync_page');
                $table->dropColumn(['missing_since', 'missing_checks', 'missing_at']);
            });
        }
    }
};
