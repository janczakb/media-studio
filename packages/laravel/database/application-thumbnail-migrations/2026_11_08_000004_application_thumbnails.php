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
        Schema::create('fms_application_thumbnail_budget', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('entries');
            $table->boolean('pressure')->default(false);
        });
        DB::table('fms_application_thumbnail_budget')->insert(['id' => 1, 'bytes' => 0, 'entries' => 0]);

        Schema::create('fms_application_thumbnails', function (Blueprint $table): void {
            $table->char('cache_key', 64)->primary();
            $table->char('token', 26);
            $table->string('disk');
            $table->string('object_key');
            $table->unsignedInteger('bytes');
            $table->string('state', 12);
            $table->timestamp('expires_at');
            $table->index(['expires_at', 'cache_key'], 'fms_application_thumb_expiry');
            $table->index(['state', 'expires_at', 'cache_key'], 'fms_application_thumb_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_application_thumbnails');
        Schema::dropIfExists('fms_application_thumbnail_budget');
    }
};
