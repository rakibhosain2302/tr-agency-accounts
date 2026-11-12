<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation of a cash / bank account with its statement: journal lines of the account are marked cleared;
 * a reconciliation completes only when the cleared balance equals the statement balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_bank_account_id')->constrained('acc_cash_bank_accounts')->restrictOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 18, 2);
            $table->string('status', 10)->default('draft');
            $table->string('notes', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['cash_bank_account_id', 'status']);
        });

        Schema::create('acc_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('acc_reconciliations')->restrictOnDelete();
            $table->foreignId('journal_line_id')->unique()->constrained('acc_journal_lines')->restrictOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE acc_reconciliations ADD CONSTRAINT acc_reconciliations_status_check CHECK (status IN ('draft','completed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_reconciliation_lines');
        Schema::dropIfExists('acc_reconciliations');
    }
};
