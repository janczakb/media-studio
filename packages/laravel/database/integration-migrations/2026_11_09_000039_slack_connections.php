<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_slack_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained('fms_workspaces')->cascadeOnDelete();
            $table->text('webhook')->nullable();
            $table->string('channel', 80);
            $table->text('events');
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('activity_cursor')->default(0);
            $table->string('principal_type', 64);
            $table->string('principal_id', 191);
            $table->timestamp('available_at');
            $table->char('lease_token', 32)->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 32)->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'available_at', 'id'], 'fms_slack_due');
        });
        Schema::table('fms_activity', fn (Blueprint $table) => $table->index(['workspace_id', 'id'], 'fms_activity_delivery'));
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_slack_connections');
        Schema::table('fms_activity', fn (Blueprint $table) => $table->dropIndex('fms_activity_delivery'));
    }
};
