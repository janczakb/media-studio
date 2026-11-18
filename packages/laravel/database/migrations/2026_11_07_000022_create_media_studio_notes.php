<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_notes', function (Blueprint $table): void {
            $table->foreignId('entry_id')->primary()->constrained('fms_entries')->cascadeOnDelete();
            $table->char('request_key', 64)->unique();
            $table->char('fingerprint', 64);
            $table->string('background', 16)->default('#ffffff');
            $table->longText('document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_notes');
    }
};
