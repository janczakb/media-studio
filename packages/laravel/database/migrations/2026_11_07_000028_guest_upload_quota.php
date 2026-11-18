<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_shares', function (Blueprint $table): void {
            $table->unsignedBigInteger('guest_upload_bytes')->default(0);
            $table->unsignedInteger('guest_upload_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('fms_shares', fn (Blueprint $table) => $table->dropColumn(['guest_upload_bytes', 'guest_upload_count']));
    }
};
