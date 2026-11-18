<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->restrictOnDelete();
            $table->foreignId('entry_id')->constrained('fms_entries')->restrictOnDelete();
            $table->ulid('version_id')->nullable();
            $table->string('owner_type', 64);
            $table->string('owner_id', 191);
            $table->char('owner_key', 64);
            $table->string('attribute', 128);
            $table->string('slot', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['owner_key', 'attribute', 'slot'], 'fms_usage_slot');
            $table->index(['entry_id', 'id'], 'fms_asset_usages');
            $table->index(['owner_key', 'attribute'], 'fms_owner_usages');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_usages');
    }
};
