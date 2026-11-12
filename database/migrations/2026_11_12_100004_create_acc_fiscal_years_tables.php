<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Financial years and their accounting periods (company-wide; branches report through journal entries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('acc_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('acc_fiscal_years')->restrictOnDelete();
            $table->unsignedTinyInteger('period_no');
            $table->string('name', 30);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'period_no']);
            $table->index(['start_date', 'end_date']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE acc_fiscal_years ADD CONSTRAINT acc_fiscal_years_status_check CHECK (status IN ('open','closed'))");
            DB::statement('ALTER TABLE acc_fiscal_years ADD CONSTRAINT acc_fiscal_years_dates_check CHECK (end_date > start_date)');
            DB::statement("ALTER TABLE acc_periods ADD CONSTRAINT acc_periods_status_check CHECK (status IN ('open','closed'))");
            DB::statement('ALTER TABLE acc_periods ADD CONSTRAINT acc_periods_dates_check CHECK (end_date >= start_date)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_periods');
        Schema::dropIfExists('acc_fiscal_years');
    }
};
