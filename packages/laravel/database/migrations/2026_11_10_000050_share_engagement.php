<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fms_shares', function (Blueprint $table): void {
            $table->text('encrypted_token')->nullable();
            $table->string('visibility', 12)->default('share');
            $table->boolean('collect_analytics')->default(false);
            $table->boolean('allow_indexing')->default(false);
            $table->boolean('allow_copy')->default(false);
            $table->string('recipient_email', 254)->nullable();
            $table->string('recipient_permission', 12)->nullable();
        });
        Schema::create('fms_share_guest_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('share_id')->constrained('fms_shares')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('created_at');
            $table->index(['share_id', 'id']);
        });
        Schema::create('fms_share_daily', function (Blueprint $table): void {
            $table->foreignId('share_id')->constrained('fms_shares')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedTinyInteger('stripe');
            $table->unsignedBigInteger('visits');
            $table->unsignedBigInteger('duration_seconds');
            $table->unsignedBigInteger('measured_visits');
            $table->timestamp('last_visited_at')->nullable();
            $table->primary(['share_id', 'day', 'stripe']);
        });
        Schema::create('fms_share_countries', function (Blueprint $table): void {
            $table->foreignId('share_id')->constrained('fms_shares')->cascadeOnDelete();
            $table->date('day');
            $table->char('country', 2);
            $table->unsignedBigInteger('visits');
            $table->primary(['share_id', 'day', 'country']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_share_guest_comments');
        Schema::dropIfExists('fms_share_countries');
        Schema::dropIfExists('fms_share_daily');
        Schema::table('fms_shares', fn (Blueprint $table) => $table->dropColumn([
            'encrypted_token', 'visibility', 'collect_analytics', 'allow_indexing', 'allow_copy', 'recipient_email', 'recipient_permission',
        ]));
    }
};
