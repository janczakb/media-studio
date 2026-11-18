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
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('thumbnail_state', 16)->default('none');
            $table->string('thumbnail_disk')->nullable();
            $table->string('thumbnail_key', 1024)->nullable();
            $table->ulid('thumbnail_version_id')->nullable();
        });
        Schema::create('fms_renditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id')->constrained('fms_entries')->cascadeOnDelete();
            $table->ulid('version_id');
            $table->string('preset', 32);
            $table->string('state', 16)->default('queued');
            $table->char('lease_token', 32)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('disk')->nullable();
            $table->string('object_key', 1024)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['version_id', 'preset']);
            $table->index(['state', 'lease_expires_at', 'id'], 'fms_rendition_work');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_renditions');
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->dropColumn(['width', 'height', 'thumbnail_state', 'thumbnail_disk', 'thumbnail_key', 'thumbnail_version_id']);
        });
    }
};
