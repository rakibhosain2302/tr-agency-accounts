<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journal entries (the financial transactions) and their lines (journal entry details).
 * Debit and credit are in the base currency; a posted entry is never changed again, only reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->nullable()->constrained('acc_fiscal_years')->restrictOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('acc_periods')->restrictOnDelete();
            $table->unsignedInteger('sequence_no')->nullable();
            $table->string('entry_no', 40)->nullable();
            $table->date('entry_date')->index();
            $table->string('entry_type', 12)->default('manual');
            $table->string('status', 10)->default('draft')->index();
            $table->string('reference', 100)->nullable();
            $table->string('narration', 500)->nullable();
            $table->string('source_type', 191)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_key', 191)->nullable()->unique();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('acc_journal_entries')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'entry_no']);
            $table->unique(['branch_id', 'fiscal_year_id', 'sequence_no']);
            $table->index(['source_type', 'source_id']);
        });

        $partiesTable = config('accounts.parties.table');

        Schema::create('acc_journal_lines', function (Blueprint $table) use ($partiesTable) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('acc_journal_entries')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('party_id')->nullable();
            if ($partiesTable) {
                $table->foreign('party_id')->references('id')->on($partiesTable)->restrictOnDelete();
            }
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->char('currency_code', 3)->nullable();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('fc_debit', 18, 2)->default(0);
            $table->decimal('fc_credit', 18, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->unique(['journal_entry_id', 'line_no']);
            $table->index(['account_id', 'journal_entry_id']);
            $table->index(['party_id', 'account_id']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE acc_journal_entries ADD CONSTRAINT acc_journal_entries_status_check CHECK (status IN ('draft','posted','reversed'))");
            DB::statement("ALTER TABLE acc_journal_entries ADD CONSTRAINT acc_journal_entries_type_check CHECK (entry_type IN ('manual','system','opening','closing','reversal'))");
            // A posted or reversed entry always has its period and number.
            DB::statement("ALTER TABLE acc_journal_entries ADD CONSTRAINT acc_journal_entries_posted_check CHECK (status = 'draft' OR (period_id IS NOT NULL AND fiscal_year_id IS NOT NULL AND entry_no IS NOT NULL AND posted_at IS NOT NULL))");
            DB::statement('ALTER TABLE acc_journal_lines ADD CONSTRAINT acc_journal_lines_side_check CHECK (debit >= 0 AND credit >= 0 AND ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)))');
            DB::statement('ALTER TABLE acc_journal_lines ADD CONSTRAINT acc_journal_lines_fc_check CHECK (fc_debit >= 0 AND fc_credit >= 0 AND exchange_rate > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_journal_lines');
        Schema::dropIfExists('acc_journal_entries');
    }
};
