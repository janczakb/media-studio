<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_search_syncs', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->string('phase', 8)->default('scan');
            $table->unsignedBigInteger('after_id')->default(0);
            $table->unsignedBigInteger('task_id')->nullable();
            $table->text('pending')->nullable();
        });
        Schema::create('fms_search_receipts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('sync_id', 64);
            $table->unsignedBigInteger('entry_id');
            $table->char('public_id', 26);
            $table->char('fingerprint', 64);
            $table->unique(['sync_id', 'entry_id']);
            $table->index(['sync_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_search_receipts');
        Schema::dropIfExists('fms_search_syncs');
    }
};
