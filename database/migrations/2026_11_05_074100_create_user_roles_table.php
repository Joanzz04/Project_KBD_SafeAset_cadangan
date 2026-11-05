<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedTinyInteger('role_id');
            $table->foreign('role_id')->references('id')->on('roles');
            $table->enum('status', ['PENDING', 'ACTIVE', 'REVOKED'])->default('PENDING');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->dateTime('reviewed_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'role_id']);
        });

        foreach (['INSERT', 'UPDATE'] as $event) {
            DB::unprepared("
                CREATE TRIGGER trg_role_review_" . strtolower($event) . " BEFORE $event ON user_roles
                FOR EACH ROW
                BEGIN
                  IF NEW.status = 'ACTIVE'
                     AND NEW.role_id <> (SELECT id FROM roles WHERE code = 'BUYER')
                     AND (NEW.reviewed_by IS NULL OR NEW.reviewed_by = NEW.user_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Peran perlu disetujui Admin lain';
                  END IF;
                END
            ");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_role_review_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_role_review_update');
        Schema::dropIfExists('user_roles');
    }
};