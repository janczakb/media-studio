<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_workspaces', function (Blueprint $table): void {
            $table->string('creation_request', 64)->nullable()->unique();
            $table->string('creation_fingerprint', 64)->nullable();
        });
        Schema::table('fms_upload_sessions', function (Blueprint $table): void {
            $table->string('client_scope', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fms_upload_sessions', function (Blueprint $table): void {
            $table->dropColumn('client_scope');
        });
        Schema::table('fms_workspaces', function (Blueprint $table): void {
            $table->dropUnique(['creation_request']);
            $table->dropColumn(['creation_request', 'creation_fingerprint']);
        });
    }
};
