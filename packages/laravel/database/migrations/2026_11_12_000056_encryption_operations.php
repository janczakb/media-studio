<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_comments', function (Blueprint $t): void {
            $t->longText('body')->change();
            $t->longText('mentions')->change();
            $t->longText('document')->nullable()->change();
        });
        Schema::table('fms_entries', fn (Blueprint $t) => $t->longText('note_excerpt')->nullable()->change());
        Schema::create('fms_encryption_operations', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('kind', 24);
            $t->string('state', 24)->default('pending');
            $t->json('options');
            $t->json('checkpoint')->nullable();
            $t->unsignedBigInteger('processed')->default(0);
            $t->string('lease', 64)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->string('error', 160)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Keep the widened excerpt column: truncating ciphertext is not a safe rollback.
        Schema::dropIfExists('fms_encryption_operations');
    }
};
