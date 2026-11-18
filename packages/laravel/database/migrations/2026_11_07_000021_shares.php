<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_shares', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->string('creator_type', 64);
            $table->string('creator_id', 191);
            $table->char('creator_key', 64);
            $table->string('title', 160);
            $table->string('kind', 12);
            $table->char('token_hash', 64)->unique();
            $table->boolean('allow_download')->default(false);
            $table->string('password_hash')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedSmallInteger('entry_count');
            $table->timestamps();
            $table->index(['workspace_id', 'id']);
            $table->index(['workspace_id', 'kind', 'id']);
            $table->index(['workspace_id', 'creator_key', 'id']);
            $table->index(['workspace_id', 'revoked_at', 'expires_at', 'id'], 'fms_shares_status_index');
        });
        Schema::create('fms_share_entries', function (Blueprint $table): void {
            $table->foreignId('share_id')->constrained('fms_shares')->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('fms_entries')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->primary(['share_id', 'entry_id']);
            $table->index(['entry_id', 'share_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_share_entries');
        Schema::dropIfExists('fms_shares');
    }
};
