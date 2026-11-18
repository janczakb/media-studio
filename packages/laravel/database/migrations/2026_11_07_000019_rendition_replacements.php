<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_renditions', function (Blueprint $table): void {
            $table->text('replacement_recipe')->nullable();
            $table->string('replacement_retention', 5)->nullable();
        });

        Schema::create('fms_retired_renditions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('disk', 255);
            $table->text('object_key');
            $table->timestamp('retired_at');
            $table->timestamp('delete_after')->nullable();
            $table->index(['workspace_id', 'delete_after', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_retired_renditions');
        Schema::table('fms_renditions', fn (Blueprint $table) => $table->dropColumn(['replacement_recipe', 'replacement_retention']));
    }
};
