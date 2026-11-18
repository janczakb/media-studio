<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_slack_connections', function (Blueprint $table): void {
            $table->string('slack_team_id', 32)->nullable();
            $table->string('slack_team_name', 120)->nullable();
            $table->string('slack_channel_id', 32)->nullable();
        });
        Schema::create('fms_slack_oauth_states', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $table->char('principal_key', 64);
            $table->char('session_key', 64);
            $table->unsignedBigInteger('connection_revision');
            $table->text('return_url');
            $table->text('redirect_uri');
            $table->timestamp('expires_at');
            $table->index(['principal_key', 'expires_at'], 'fms_slack_oauth_actor');
            $table->index('expires_at', 'fms_slack_oauth_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_slack_oauth_states');
        Schema::table('fms_slack_connections', fn (Blueprint $table) => $table->dropColumn(['slack_team_id', 'slack_team_name', 'slack_channel_id']));
    }
};
