<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Financial transactions: income, expense, receipt (money in from a party) and payment (money out to a party).
 * Posting one writes its balanced journal entry (journal_entry_id); reversing it writes the reversal entry.
 * Amounts are in the base currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        $partiesTable = config('accounts.parties.table');

        Schema::create('acc_transactions', function (Blueprint $table) use ($partiesTable) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('transaction_type', 10);
            $table->string('status', 10)->default('draft')->index();
            $table->foreignId('fiscal_year_id')->nullable()->constrained('acc_fiscal_years')->restrictOnDelete();
            $table->unsignedInteger('sequence_no')->nullable();
            $table->string('transaction_no', 40)->nullable();
            $table->date('transaction_date')->index();
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('party_id')->nullable()->index();
            if ($partiesTable) {
                $table->foreign('party_id')->references('id')->on($partiesTable)->restrictOnDelete();
            }
            $table->foreignId('cash_bank_account_id')->nullable()->constrained('acc_cash_bank_accounts')->restrictOnDelete();
            $table->foreignId('counter_account_id')->nullable()->constrained('acc_accounts')->restrictOnDelete();
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('reference', 100)->nullable();
            $table->string('narration', 500)->nullable();
            $table->string('source_type', 191)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_key', 191)->nullable()->unique();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('acc_journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('acc_journal_entries')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'transaction_no']);
            $table->unique(['branch_id', 'transaction_type', 'fiscal_year_id', 'sequence_no'], 'acc_transactions_sequence_unique');
            $table->index(['transaction_type', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('acc_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('acc_transactions')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->unique(['transaction_id', 'line_no']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE acc_transactions ADD CONSTRAINT acc_transactions_type_check CHECK (transaction_type IN ('income','expense','receipt','payment'))");
            DB::statement("ALTER TABLE acc_transactions ADD CONSTRAINT acc_transactions_status_check CHECK (status IN ('draft','posted','reversed'))");
            DB::statement('ALTER TABLE acc_transactions ADD CONSTRAINT acc_transactions_total_check CHECK (total_amount >= 0)');
            // A posted or reversed transaction always has its number and its journal entry.
            DB::statement("ALTER TABLE acc_transactions ADD CONSTRAINT acc_transactions_posted_check CHECK (status = 'draft' OR (transaction_no IS NOT NULL AND journal_entry_id IS NOT NULL AND posted_at IS NOT NULL AND total_amount > 0))");
            DB::statement("ALTER TABLE acc_transactions ADD CONSTRAINT acc_transactions_reversed_check CHECK (status <> 'reversed' OR reversal_journal_entry_id IS NOT NULL)");
            DB::statement('ALTER TABLE acc_transaction_lines ADD CONSTRAINT acc_transaction_lines_amount_check CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_transaction_lines');
        Schema::dropIfExists('acc_transactions');
    }
};
