<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_ai_actions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('run_id');
            $t->foreign('run_id')->references('id')->on('fms_ai_runs')->cascadeOnDelete();
            $t->string('operation', 32);
            $t->string('input_hash', 64);
            $t->json('result');
            $t->json('undo')->nullable();
            $t->timestamp('undone_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['run_id', 'operation', 'input_hash'], 'fms_ai_action_receipt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_ai_actions');
    }
};
