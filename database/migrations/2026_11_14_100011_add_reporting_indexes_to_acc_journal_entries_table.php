<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports read posted entries by date and branch: one composite index serves every report's filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_journal_entries', function (Blueprint $table) {
            $table->index(['status', 'entry_date', 'branch_id'], 'acc_journal_entries_report_index');
        });
    }

    public function down(): void
    {
        Schema::table('acc_journal_entries', function (Blueprint $table) {
            $table->dropIndex('acc_journal_entries_report_index');
        });
    }
};
