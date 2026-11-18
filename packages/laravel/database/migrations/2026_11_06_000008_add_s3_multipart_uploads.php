<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_upload_sessions', function (Blueprint $table): void {
            $table->string('transport', 24)->default('local');
            $table->string('disk')->nullable();
            $table->ulid('version_id')->nullable();
            $table->string('object_key', 1024)->nullable();
            $table->char('storage_target', 64)->nullable();
            $table->text('provider_upload_id')->nullable();
            $table->index(['workspace_id', 'cleaned_at'], 'fms_session_reserved');
        });
        Schema::table('fms_upload_parts', function (Blueprint $table): void {
            $table->string('provider_etag', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fms_upload_parts', function (Blueprint $table): void {
            $table->dropColumn('provider_etag');
        });
        Schema::table('fms_upload_sessions', function (Blueprint $table): void {
            $table->dropIndex('fms_session_reserved');
            $table->dropColumn(['transport', 'disk', 'version_id', 'object_key', 'storage_target', 'provider_upload_id']);
        });
    }
};
