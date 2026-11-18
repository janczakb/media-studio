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
            $table->json('studio_settings')->nullable();
            $table->unsignedInteger('studio_settings_revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('fms_workspaces', function (Blueprint $table): void {
            $table->dropColumn(['studio_settings', 'studio_settings_revision']);
        });
    }
};
