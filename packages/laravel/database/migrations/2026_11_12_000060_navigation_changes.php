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
            $table->unsignedBigInteger('navigation_revision')->default(1);
        });
        Schema::create('fms_navigation_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('revision');
            $table->string('kind', 24);
            $table->json('payload');
            $table->timestamp('created_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->string('lease_token', 36)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->index(['published_at', 'available_at', 'id'], 'fms_navigation_delivery');
            $table->unique(['workspace_id', 'revision']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_navigation_changes');
        Schema::table('fms_workspaces', fn (Blueprint $table) => $table->dropColumn('navigation_revision'));
    }
};
