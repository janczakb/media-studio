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
        $modifier = DB::getDriverName() === 'sqlite' ? 'virtualAs' : 'storedAs';
        Schema::table('fms_entries', function (Blueprint $table) use ($modifier): void {
            $table->unsignedTinyInteger('photo_media')->$modifier("CASE WHEN kind = 'file' AND media_type IN ('image', 'video') THEN 1 ELSE 0 END");
            $table->dateTime('photo_created')->$modifier("COALESCE(created_at, updated_at, '1970-01-02 00:00:00')");
        });
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->index(['workspace_id', 'photo_media', 'trashed_at', 'photo_created', 'id'], 'fms_photo_timeline');
            $table->index(['workspace_id', 'media_type', 'photo_media', 'trashed_at', 'photo_created', 'id'], 'fms_photo_type_timeline');
        });
    }

    public function down(): void
    {
        Schema::table('fms_entries', function (Blueprint $table): void {
            $table->dropIndex('fms_photo_timeline');
            $table->dropIndex('fms_photo_type_timeline');
        });
        Schema::table('fms_entries', fn (Blueprint $table) => $table->dropColumn(['photo_media', 'photo_created']));
    }
};
