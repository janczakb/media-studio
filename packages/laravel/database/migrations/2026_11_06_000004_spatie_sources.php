<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->string('source_kind', 24)->default('managed');
        });
        Schema::table('fms_asset_versions', fn (Blueprint $table) => $table->char('sha256', 64)->nullable()->change());
        Schema::create('fms_spatie_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->string('mount_key', 64);
            $table->string('media_key', 191)->nullable();
            $table->char('source_key', 64);
            $table->char('source_revision', 64);
            $table->foreignId('entry_id')->unique()->constrained('fms_entries')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'mount_key', 'source_key'], 'fms_spatie_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_spatie_sources');
        Schema::table('fms_entries', fn (Blueprint $table) => $table->dropColumn('source_kind'));
    }
};
