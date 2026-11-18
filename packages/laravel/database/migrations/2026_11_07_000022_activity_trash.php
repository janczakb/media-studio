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
            $table->timestamp('purging_at')->nullable();
            $table->char('trashed_by_key', 64)->nullable();
            $table->string('trashed_by_type', 64)->nullable();
            $table->string('trashed_by_id', 191)->nullable();
            $table->index(['workspace_id', 'source_kind', 'trashed_at', 'id'], 'fms_trash_retention');
            $table->index(['workspace_id', 'trashed_by_key', 'trashed_at', 'id'], 'fms_trash_actor');
        });
        Schema::create('fms_activity', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->ulid('entry_public_id');
            $table->char('event_key', 64)->unique();
            $table->string('event', 32);
            $table->string('actor_type', 64);
            $table->string('actor_id', 191);
            $table->char('actor_key', 64);
            $table->string('name', 191);
            $table->string('kind', 12);
            $table->string('media_type', 24)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('revision');
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'event', 'id'], 'fms_activity_event');
            $table->index(['workspace_id', 'actor_key', 'event', 'id'], 'fms_activity_actor');
        });
        Schema::create('fms_notifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->char('principal_key', 64);
            $table->foreignId('activity_id')->constrained('fms_activity')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['activity_id', 'principal_key']);
            $table->index(['workspace_id', 'principal_key', 'id'], 'fms_notification_inbox');
            $table->index(['workspace_id', 'principal_key', 'read_at', 'id'], 'fms_notification_unread');
        });
        Schema::create('fms_trash_purges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->ulid('workspace_public_id');
            $table->unsignedBigInteger('entry_id')->unique();
            $table->ulid('entry_public_id');
            $table->ulid('version_id')->nullable();
            $table->unsignedBigInteger('revision');
            $table->string('state', 16)->default('queued');
            $table->string('principal_type', 64);
            $table->string('principal_id', 191);
            $table->string('tenant_type', 64)->nullable();
            $table->string('tenant_id', 191)->nullable();
            $table->char('lease_token', 32)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'state', 'id'], 'fms_purge_dispatch');
        });
        Schema::create('fms_trash_purge_objects', function (Blueprint $table): void {
            $table->id();
            $table->ulid('purge_id');
            $table->foreign('purge_id')->references('id')->on('fms_trash_purges')->cascadeOnDelete();
            $table->string('disk', 255);
            $table->text('object_key');
            $table->char('object_hash', 64);
            $table->timestamp('deleted_at')->nullable();
            $table->unique(['purge_id', 'object_hash']);
            $table->index(['purge_id', 'deleted_at', 'id'], 'fms_purge_pending_objects');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_trash_purge_objects');
        Schema::dropIfExists('fms_trash_purges');
        Schema::dropIfExists('fms_notifications');
        Schema::dropIfExists('fms_activity');
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->dropIndex('fms_trash_retention');
            $table->dropIndex('fms_trash_actor');
            $table->dropColumn(['purging_at', 'trashed_by_key', 'trashed_by_type', 'trashed_by_id']);
        });
    }
};
