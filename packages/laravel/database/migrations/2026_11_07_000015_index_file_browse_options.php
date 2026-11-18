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
        $category = <<<'SQL'
            CASE
                WHEN kind = 'folder' THEN ''
                WHEN media_type IN ('image', 'video', 'audio') THEN media_type
                WHEN mime_type = 'application/pdf' THEN 'pdf'
                WHEN media_type = 'text'
                    OR mime_type IN ('application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/rtf')
                    OR mime_type LIKE 'application/vnd.openxmlformats-officedocument.%'
                    OR mime_type LIKE 'application/vnd.oasis.opendocument.%'
                    THEN 'document'
                ELSE 'other'
            END
            SQL;

        Schema::table('fms_entries', function (Blueprint $table) use ($modifier, $category): void {
            $table->unsignedBigInteger('browse_size')->$modifier('COALESCE(size_bytes, 0)');
            $table->string('browse_type', 16)->$modifier($category);
            $table->dateTime('browse_updated')->$modifier("COALESCE(updated_at, created_at, '1970-01-02 00:00:00')");
        });

        Schema::table('fms_entries', function (Blueprint $table): void {
            foreach (['size', 'type', 'updated'] as $sort) {
                $table->index(['workspace_id', 'parent_scope', 'trashed_at', 'browse_'.$sort, 'id'], 'fms_browse_'.$sort);
            }
        });
    }

    public function down(): void
    {
        Schema::table('fms_entries', function (Blueprint $table): void {
            foreach (['size', 'type', 'updated'] as $sort) {
                $table->dropIndex('fms_browse_'.$sort);
            }
        });
        Schema::table('fms_entries', fn (Blueprint $table) => $table->dropColumn(['browse_size', 'browse_type', 'browse_updated']));
    }
};
