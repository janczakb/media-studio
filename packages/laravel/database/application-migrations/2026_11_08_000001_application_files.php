<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_application_entries', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->char('tenant_scope', 64);
            $table->string('source_key', 64);
            $table->char('source_revision', 64);
            $table->char('identity', 64);
            $table->char('parent_key', 64);
            $table->string('kind', 8);
            $table->string('name');
            $table->string('sort_name');
            $table->string('remote_id', 191)->nullable();
            $table->text('descriptor')->nullable();
            $table->timestamps();
            $table->unique(['tenant_scope', 'source_key', 'identity'], 'fms_application_identity');
            $table->index(['tenant_scope', 'source_key', 'source_revision', 'parent_key', 'sort_name', 'id'], 'fms_application_browse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_application_entries');
    }
};
