<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_ai_profiles', function (Blueprint $t): void {
            $t->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $t->string('principal_key', 64);
            $t->unsignedInteger('revision')->default(1);
            $t->string('name', 60);
            $t->json('character');
            $t->timestamps();
            $t->primary(['workspace_id', 'principal_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_ai_profiles');
    }
};
