<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_workspace_limits', function (Blueprint $t): void {
            $t->string('key', 64)->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_workspace_limits');
    }
};
