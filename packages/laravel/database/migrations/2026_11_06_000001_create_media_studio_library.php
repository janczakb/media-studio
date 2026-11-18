<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 160);
            $table->string('disk');
            $table->string('storage_root');
            $table->timestamps();
        });

        Schema::create('fms_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->string('principal_type', 64);
            $table->string('principal_id', 191);
            $table->string('role', 24);
            $table->unique(['workspace_id', 'principal_type', 'principal_id'], 'fms_member_identity');
            $table->index(['principal_type', 'principal_id', 'workspace_id'], 'fms_member_workspaces');
        });

        Schema::create('fms_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('fms_entries')->restrictOnDelete();
            $table->unsignedBigInteger('parent_scope')->default(0);
            $table->string('kind', 12);
            $table->unsignedTinyInteger('sort_group');
            $table->string('name', 191);
            $table->string('normalized_name', 191);
            $table->string('media_type', 24)->nullable();
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->ulid('current_version_id')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'parent_scope', 'kind', 'normalized_name'], 'fms_entry_name');
            $table->index(['workspace_id', 'parent_scope', 'sort_group', 'normalized_name', 'id'], 'fms_browse');
            $table->index(['workspace_id', 'media_type', 'id'], 'fms_photos');
        });

        Schema::create('fms_asset_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('entry_id')->constrained('fms_entries')->cascadeOnDelete();
            $table->string('disk');
            $table->string('object_key', 1024);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 160);
            $table->timestamp('created_at');
        });

        Schema::create('fms_upload_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->char('key_hash', 64);
            $table->char('fingerprint', 64);
            $table->string('state', 24);
            $table->foreignId('entry_id')->nullable()->constrained('fms_entries')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_upload_receipts');
        Schema::dropIfExists('fms_asset_versions');
        Schema::dropIfExists('fms_entries');
        Schema::dropIfExists('fms_memberships');
        Schema::dropIfExists('fms_workspaces');
    }
};
