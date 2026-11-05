<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 32)->unique();
            $table->string('username', 50)->unique();
            $table->string('email', 190)->unique();
            $table->string('phone', 20);
            $table->string('password');
            $table->enum('status', ['PENDING_EMAIL', 'ACTIVE', 'SUSPENDED'])->default('PENDING_EMAIL');
            $table->unsignedTinyInteger('failed_login_count')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};