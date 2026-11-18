<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_renditions', function (Blueprint $table): void {
            $table->json('recipe')->nullable();
            $table->char('recipe_hash', 64)->nullable();
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->boolean('owned')->default(true);
            $table->string('provider', 64)->nullable();
            $table->string('principal_type', 64)->nullable();
            $table->string('principal_id', 191)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('dispatch_attempts')->default(0);
            $table->timestamp('last_dispatched_at')->nullable();
        });
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->string('thumbnail_mime_type', 160)->nullable();
            $table->string('thumbnail_preset', 32)->nullable();
            $table->string('preview_state', 16)->default('none');
            $table->string('preview_disk')->nullable();
            $table->string('preview_key', 1024)->nullable();
            $table->string('preview_mime_type', 160)->nullable();
            $table->ulid('preview_version_id')->nullable();
            $table->string('preview_preset', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->dropColumn(['thumbnail_mime_type', 'thumbnail_preset', 'preview_state', 'preview_disk', 'preview_key', 'preview_mime_type', 'preview_version_id', 'preview_preset']);
        });
        Schema::table('fms_renditions', function (Blueprint $table): void {
            $table->dropColumn(['recipe', 'recipe_hash', 'mime_type', 'size_bytes', 'sha256', 'owned', 'provider', 'principal_type', 'principal_id', 'attempts', 'dispatch_attempts', 'last_dispatched_at']);
        });
    }
};
