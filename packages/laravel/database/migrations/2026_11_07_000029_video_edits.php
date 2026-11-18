<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_video_edits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('entry_id');
            $table->string('principal_type', 64);
            $table->string('principal_id', 191);
            $table->char('request_key', 64);
            $table->string('tenant_type', 64)->nullable();
            $table->string('tenant_id', 191)->nullable();
            $table->ulid('source_version');
            $table->unsignedBigInteger('source_revision');
            $table->double('start_seconds');
            $table->double('end_seconds');
            $table->string('state', 16)->default('queued');
            $table->string('error', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'request_key']);
            $table->index(['workspace_id', 'entry_id', 'created_at']);
            $table->index(['state', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_video_edits');
    }
};
