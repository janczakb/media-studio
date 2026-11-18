<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_push_devices', function (Blueprint $table): void {
            $table->id();
            $table->char('endpoint_hash', 64)->unique();
            $table->char('principal_key', 64);
            $table->unsignedTinyInteger('slot');
            $table->unique(['principal_key', 'slot']);
            $table->text('subscription');
            $table->string('return_path', 1024);
            $table->string('locale', 5)->default('en');
        });

        Schema::create('fms_push_preferences', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->char('principal_key', 64);
            $table->json('events');
            $table->primary(['workspace_id', 'principal_key']);
        });

        Schema::create('fms_push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_id')->constrained('fms_notifications')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('fms_push_devices')->cascadeOnDelete();
            $table->string('principal_type', 64);
            $table->string('principal_id', 191);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('expires_at')->index();
            $table->unique(['notification_id', 'device_id']);
            $table->index(['available_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_push_deliveries');
        Schema::dropIfExists('fms_push_preferences');
        Schema::dropIfExists('fms_push_devices');
    }
};
