<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_renditions', fn (Blueprint $table) => $table->string('preset', 64)->change());
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->string('thumbnail_preset', 64)->nullable()->change();
            $table->string('preview_preset', 64)->nullable()->change();
        });
        Schema::table('fms_usages', fn (Blueprint $table) => $table->string('rendition', 64)->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('fms_renditions', fn (Blueprint $table) => $table->string('preset', 32)->change());
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->string('thumbnail_preset', 32)->nullable()->change();
            $table->string('preview_preset', 32)->nullable()->change();
        });
        Schema::table('fms_usages', fn (Blueprint $table) => $table->string('rendition', 32)->nullable()->change());
    }
};
