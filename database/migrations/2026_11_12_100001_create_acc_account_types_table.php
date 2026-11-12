<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Account types: a named kind of account (e.g. "Current asset", "Bank", "Receivable") in one of the five
 * accounting categories. The category decides the normal balance (asset / expense: debit; others: credit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_account_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('category', 10)->index();
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE acc_account_types ADD CONSTRAINT acc_account_types_category_check CHECK (category IN ('asset','liability','equity','income','expense'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_account_types');
    }
};
