<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('purpose', ['VERIFY_EMAIL', 'RESET_PASSWORD']);
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verifications');
    }
};