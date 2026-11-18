<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_shares', fn (Blueprint $table) => $table->boolean('allow_upload')->default(false));
    }

    public function down(): void
    {
        Schema::table('fms_shares', fn (Blueprint $table) => $table->dropColumn('allow_upload'));
    }
};
