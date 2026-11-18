<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_shares', fn (Blueprint $table) => $table->boolean('allow_archive')->default(false));
        // Existing links already permitted bulk downloads whenever downloads were enabled.
        DB::table('fms_shares')->where('allow_download', true)->update(['allow_archive' => true]);
    }

    public function down(): void
    {
        Schema::table('fms_shares', fn (Blueprint $table) => $table->dropColumn('allow_archive'));
    }
};
