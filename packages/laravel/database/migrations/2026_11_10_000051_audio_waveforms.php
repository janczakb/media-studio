<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->char('audio_waveform', 32)->nullable();
            $table->ulid('audio_waveform_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fms_entries', fn (Blueprint $table) => $table->dropColumn(['audio_waveform', 'audio_waveform_version']));
    }
};
