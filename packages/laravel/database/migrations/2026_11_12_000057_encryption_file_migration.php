<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_workspaces', fn (Blueprint $t) => $t->ulid('encryption_operation')->nullable());
        Schema::create('fms_encryption_copies', function (Blueprint $t): void {
            $t->id();
            $t->ulid('operation_id');
            $t->foreignId('workspace_id')->constrained('fms_workspaces')->restrictOnDelete();
            $t->char('source_hash', 64);
            $t->string('source_disk');
            $t->text('source_key');
            $t->string('target_disk');
            $t->text('target_key');
            $t->char('sha256', 64)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->string('state', 24)->default('allocated');
            $t->timestamp('source_deleted_at')->nullable();
            $t->timestamps();
            $t->unique(['operation_id', 'source_hash']);
            $t->index(['workspace_id', 'source_deleted_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_encryption_copies');
        Schema::table('fms_workspaces', fn (Blueprint $t) => $t->dropColumn('encryption_operation'));
    }
};
