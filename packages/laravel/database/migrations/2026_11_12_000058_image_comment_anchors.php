<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_comments', function (Blueprint $table): void {
            $table->ulid('anchor_version_id')->nullable();
            $table->unsignedSmallInteger('anchor_x')->nullable();
            $table->unsignedSmallInteger('anchor_y')->nullable();
            $table->index(['workspace_id', 'entry_id', 'anchor_version_id', 'id'], 'fms_comments_image_anchor');
        });
    }

    public function down(): void
    {
        Schema::table('fms_comments', function (Blueprint $table): void {
            $table->dropIndex('fms_comments_image_anchor');
            $table->dropColumn(['anchor_version_id', 'anchor_x', 'anchor_y']);
        });
    }
};
