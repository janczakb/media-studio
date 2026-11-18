<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_ai_slots', function (Blueprint $t): void {
            $t->string('key', 64)->primary();
            $t->unsignedInteger('active')->default(0);
        });
        Schema::table('fms_ai_runs', function (Blueprint $t): void {
            $t->string('slot_key', 64)->nullable();
            $t->text('partial_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fms_ai_runs', fn (Blueprint $t) => $t->dropColumn(['slot_key', 'partial_text']));
        Schema::dropIfExists('fms_ai_slots');
    }
};
