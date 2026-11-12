<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of accounts: the postable accounts, each in one group (and so of the group's type).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_group_id')->constrained('acc_account_groups')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->boolean('is_control')->default(false);
            $table->char('currency_code', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_accounts');
    }
};
