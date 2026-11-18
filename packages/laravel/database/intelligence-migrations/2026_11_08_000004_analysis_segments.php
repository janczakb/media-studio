<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_analysis_segments', function (Blueprint $table): void {
            $table->char('analysis_id', 64);
            $table->foreign('analysis_id')->references('id')->on('fms_image_analyses')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordinal');
            $table->decimal('time_seconds', 10, 3);
            $table->string('description', 2000);
            $table->text('labels');
            $table->primary(['analysis_id', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_analysis_segments');
    }
};
