<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_comments', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('workspace_id')->constrained('fms_workspaces')->cascadeOnDelete();
            $t->foreignId('entry_id')->constrained('fms_entries')->cascadeOnDelete();
            $t->char('author_key', 64);
            $t->string('author_type', 64);
            $t->string('author_id', 191);
            $t->text('body');
            $t->text('mentions');
            $t->char('request_key', 64)->unique();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'entry_id', 'id'], 'fms_comments_feed');
            $t->index(['workspace_id', 'entry_id', 'resolved_at', 'id'], 'fms_comments_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_comments');
    }
};
