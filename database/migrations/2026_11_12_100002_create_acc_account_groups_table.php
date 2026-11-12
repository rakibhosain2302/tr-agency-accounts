<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account groups: the hierarchy of the chart of accounts (a tree). Accounts are the leaves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_account_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('acc_account_groups')->restrictOnDelete();
            $table->foreignId('account_type_id')->constrained('acc_account_types')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_account_groups');
    }
};
