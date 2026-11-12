<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash in hand, bank and mobile-wallet accounts that money is paid from or into. Each is one asset account of
 * the chart of accounts. branch_id NULL = usable by every branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_cash_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('acc_accounts')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('kind', 10);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_branch', 100)->nullable();
            $table->string('account_title', 150)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('routing_number', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE acc_cash_bank_accounts ADD CONSTRAINT acc_cash_bank_accounts_kind_check CHECK (kind IN ('cash','bank','mobile'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_cash_bank_accounts');
    }
};
