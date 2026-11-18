<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fms_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 160);
            $t->string('email', 191)->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->string('avatar_url')->nullable();
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('fms_password_reset_tokens', function (Blueprint $t): void {
            $t->string('email', 191)->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fms_password_reset_tokens');
        Schema::dropIfExists('fms_users');
    }
};
