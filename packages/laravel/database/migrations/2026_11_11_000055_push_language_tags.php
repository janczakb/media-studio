<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_push_devices', fn (Blueprint $table) => $table->string('locale', 64)->default('en')->change());
    }

    public function down(): void
    {
        // Retain the wider column so rollback never truncates existing language tags.
    }
};
