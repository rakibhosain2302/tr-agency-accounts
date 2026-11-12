<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settlement of open items: a receipt against income on credit (receivable), a payment against expense on
 * credit (payable). Several allocations make partial payments; what is not allocated stays outstanding
 * (or, on the receipt / payment side, an advance). An allocation is undone by marking it reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('acc_transactions')->restrictOnDelete();
            $table->foreignId('target_id')->constrained('acc_transactions')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->date('allocation_date');
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['settlement_id', 'reversed_at']);
            $table->index(['target_id', 'reversed_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE acc_allocations ADD CONSTRAINT acc_allocations_amount_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE acc_allocations ADD CONSTRAINT acc_allocations_distinct_check CHECK (settlement_id <> target_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_allocations');
    }
};
