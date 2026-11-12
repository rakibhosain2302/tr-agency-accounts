<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a control account controls: receivable (what parties owe: the customer side) or payable (what is owed
 * to parties: the supplier side). Customers and suppliers stay generic parties; the account decides the side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_accounts', function (Blueprint $table) {
            $table->string('control_kind', 12)->nullable()->after('is_control')->index();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE acc_accounts ADD CONSTRAINT acc_accounts_control_kind_check CHECK (control_kind IS NULL OR (is_control = 1 AND control_kind IN ('receivable','payable')))");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE acc_accounts DROP CONSTRAINT acc_accounts_control_kind_check');
        }

        Schema::table('acc_accounts', function (Blueprint $table) {
            $table->dropIndex(['control_kind']);
            $table->dropColumn('control_kind');
        });
    }
};
