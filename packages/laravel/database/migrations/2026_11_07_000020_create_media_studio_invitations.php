<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_memberships', function (Blueprint $table): void {
            $table->index(['workspace_id', 'id'], 'fms_members_cursor');
        });
        Schema::create('fms_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->string('email', 254);
            $table->char('email_key', 64);
            $table->string('role', 24);
            $table->char('token_hash', 64)->unique();
            $table->string('inviter_type', 64);
            $table->string('inviter_id', 191);
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'email_key']);
            $table->index(['workspace_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_invitations');
        Schema::table('fms_memberships', fn (Blueprint $table) => $table->dropIndex('fms_members_cursor'));
    }
};
