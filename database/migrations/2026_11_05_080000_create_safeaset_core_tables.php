<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== DOKUMEN & IDENTITAS =====
        Schema::create('uploaded_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users');
            $t->enum('purpose', ['KTP', 'CERTIFICATE', 'PHOTO']);
            $t->string('original_name');
            $t->char('stored_name', 40)->unique();
            $t->string('mime_type', 100);
            $t->unsignedInteger('size_bytes');
            $t->char('sha256', 64);
            $t->timestamp('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE uploaded_files ADD CONSTRAINT ck_file_size CHECK (size_bytes BETWEEN 1024 AND 5242880)');

        Schema::create('user_identities', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users');
            $t->text('nik_encrypted');
            $t->char('nik_blind_index', 64)->unique();
            $t->foreignId('ktp_file_id')->constrained('uploaded_files');
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->string('bank_name', 60);
            $t->string('account_holder', 120);
            $t->text('account_encrypted');
            $t->char('account_blind_index', 64);
            $t->char('account_last4', 4);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['user_id', 'account_blind_index']);
        });

        // ===== PROPERTI & PENAWARAN =====
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 32)->unique();
            $t->foreignId('seller_id')->constrained('users');
            $t->foreignId('validated_by')->nullable()->constrained('users');
            $t->string('title', 150);
            $t->text('description');
            $t->decimal('price', 15, 2);
            $t->string('city', 80);
            $t->string('district', 80);
            $t->text('address_encrypted');
            $t->text('certificate_encrypted');
            $t->char('certificate_blind_index', 64)->unique();
            $t->foreignId('certificate_file_id')->constrained('uploaded_files');
            $t->enum('status', ['PENDING_REVIEW', 'AVAILABLE', 'RESERVED', 'SOLD', 'REMOVED'])->default('PENDING_REVIEW');
            $t->string('review_note')->nullable();
            $t->timestamps();
            $t->index(['status', 'city', 'price'], 'idx_prop_search');
        });
        DB::statement('ALTER TABLE properties ADD CONSTRAINT ck_prop_price CHECK (price > 0)');

        Schema::create('offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('property_id')->constrained('properties');
            $t->foreignId('buyer_id')->constrained('users');
            $t->foreignId('parent_offer_id')->nullable()->constrained('offers');
            $t->decimal('amount', 15, 2);
            $t->enum('status', ['SUBMITTED', 'COUNTERED', 'ACCEPTED', 'REJECTED', 'WITHDRAWN', 'EXPIRED'])->default('SUBMITTED');
            $t->dateTime('expires_at');
            $t->timestamps();
            $t->index(['property_id', 'status']);
        });
        DB::statement('ALTER TABLE offers ADD CONSTRAINT ck_offer_amount CHECK (amount > 0)');

        // ===== TRANSAKSI & ESCROW =====
        Schema::create('transactions', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 32)->unique();
            $t->foreignId('property_id')->constrained('properties');
            $t->foreignId('offer_id')->unique()->constrained('offers');
            $t->foreignId('buyer_id')->constrained('users');
            $t->foreignId('seller_id')->constrained('users');
            $t->foreignId('agent_id')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->decimal('agreed_price', 15, 2);
            $t->decimal('commission_amount', 15, 2);
            $t->decimal('platform_fee', 15, 2);
            $t->enum('status', ['AWAITING_ESCROW', 'ESCROW_FUNDED', 'IN_VERIFICATION', 'COMPLETED', 'CANCELLED', 'DISPUTED'])
                ->default('AWAITING_ESCROW');
            $t->unsignedInteger('version')->default(0);
            // BR-02: hanya satu transaksi aktif per properti, dipaksa oleh UNIQUE di database
            $t->unsignedBigInteger('active_property_id')->nullable()->storedAs(
                "CASE WHEN status IN ('AWAITING_ESCROW','ESCROW_FUNDED','IN_VERIFICATION','DISPUTED') THEN property_id ELSE NULL END"
            );
            $t->timestamps();
            $t->unique('active_property_id', 'uq_one_active_per_property');
        });
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT ck_price CHECK (agreed_price > 0)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT ck_fees CHECK (commission_amount >= 0 AND platform_fee >= 0 AND commission_amount + platform_fee <= agreed_price)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT ck_distinct_parties CHECK (buyer_id <> seller_id AND buyer_id <> agent_id AND seller_id <> agent_id)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT ck_admin_not_party CHECK (approved_by IS NULL OR (approved_by <> buyer_id AND approved_by <> seller_id AND approved_by <> agent_id))');

        Schema::create('escrow_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transaction_id')->unique()->constrained('transactions');
            $t->string('virtual_account_no', 30)->unique();
            $t->decimal('expected_amount', 15, 2);
            $t->enum('status', ['AWAITING_FUNDS', 'FUNDED', 'RELEASED', 'REFUNDED', 'DISPUTED'])->default('AWAITING_FUNDS');
            $t->timestamps();
        });
        DB::statement('ALTER TABLE escrow_accounts ADD CONSTRAINT ck_escrow_amount CHECK (expected_amount > 0)');

        Schema::create('escrow_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('escrow_id')->constrained('escrow_accounts');
            $t->enum('entry_type', ['DEPOSIT', 'RELEASE_SELLER', 'RELEASE_AGENT', 'RELEASE_PLATFORM', 'REFUND']);
            $t->decimal('amount', 15, 2);
            $t->foreignId('party_user_id')->nullable()->constrained('users');
            $t->string('gateway_ref', 100)->unique();
            $t->timestamp('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE escrow_ledger ADD CONSTRAINT ck_ledger_amount CHECK (amount > 0)');

        Schema::create('transaction_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transaction_id')->nullable()->constrained('transactions');
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->enum('actor_role', ['BUYER', 'SELLER', 'AGENT', 'ADMIN', 'SYSTEM']);
            $t->string('action', 60);
            $t->json('old_value')->nullable();
            $t->json('new_value')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['transaction_id', 'created_at']);
        });

        // ===== TRIGGER: tabel append-only =====
        foreach (['escrow_ledger', 'transaction_logs'] as $tbl) {
            foreach (['UPDATE', 'DELETE'] as $ev) {
                DB::unprepared("
                    CREATE TRIGGER trg_{$tbl}_no_" . strtolower($ev) . " BEFORE $ev ON $tbl
                    FOR EACH ROW
                    BEGIN
                      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$tbl bersifat append-only';
                    END
                ");
            }
        }

        // ===== TRIGGER: BR-06, dana keluar tidak boleh melebihi total deposit =====
        DB::unprepared("
            CREATE TRIGGER trg_ledger_no_overspend BEFORE INSERT ON escrow_ledger
            FOR EACH ROW
            BEGIN
              DECLARE v_in DECIMAL(15,2);
              DECLARE v_out DECIMAL(15,2);
              IF NEW.entry_type <> 'DEPOSIT' THEN
                SELECT COALESCE(SUM(CASE WHEN entry_type = 'DEPOSIT' THEN amount END), 0),
                       COALESCE(SUM(CASE WHEN entry_type <> 'DEPOSIT' THEN amount END), 0)
                  INTO v_in, v_out FROM escrow_ledger WHERE escrow_id = NEW.escrow_id;
                IF v_out + NEW.amount > v_in THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dana keluar melebihi total deposit';
                END IF;
              END IF;
            END
        ");
    }

    public function down(): void
    {
        foreach (['escrow_ledger', 'transaction_logs'] as $tbl) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_{$tbl}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS trg_{$tbl}_no_delete");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ledger_no_overspend');

        foreach (['transaction_logs', 'escrow_ledger', 'escrow_accounts', 'transactions', 'offers',
                  'properties', 'bank_accounts', 'user_identities', 'uploaded_files'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
