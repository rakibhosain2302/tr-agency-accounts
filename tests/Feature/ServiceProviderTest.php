<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nit\AgraniAccounts\Contracts\EntryNumberGenerator;
use Nit\AgraniAccounts\Models\AccountType;
use Nit\AgraniAccounts\Support\DatabaseEntryNumberGenerator;
use Nit\AgraniAccounts\Tests\TestCase;
use Nit\Master\Http\Controllers\RoleController;

class ServiceProviderTest extends TestCase
{
    public function test_config_binding_and_routes_are_registered(): void
    {
        $this->assertSame('JV-{branch}-{year}-{number}', config('accounts.numbering.format'));
        $this->assertInstanceOf(DatabaseEntryNumberGenerator::class, app(EntryNumberGenerator::class));

        foreach (['fiscal-years.index', 'periods.close', 'account-types.store', 'account-groups.index', 'accounts.store', 'journal-entries.store', 'journal-entries.post', 'journal-entries.reverse'] as $name) {
            $this->assertTrue(Route::has('accounts.api.'.$name), $name);
        }
    }

    public function test_migrations_create_the_thirteen_accounting_tables_only(): void
    {
        $schema = DB::getDriverName() === 'sqlite' ? null : DB::connection()->getDatabaseName();
        $tables = array_values(array_filter(Schema::getTableListing($schema, schemaQualified: false), fn (string $table): bool => str_starts_with($table, 'acc_')));
        sort($tables);

        $this->assertSame(['acc_account_groups', 'acc_account_types', 'acc_accounts', 'acc_allocations', 'acc_cash_bank_accounts', 'acc_fiscal_years', 'acc_journal_entries', 'acc_journal_lines', 'acc_periods', 'acc_reconciliation_lines', 'acc_reconciliations', 'acc_transaction_lines', 'acc_transactions'], $tables);
    }

    public function test_every_ability_shows_on_nit_masters_role_form(): void
    {
        $keys = array_keys(RoleController::abilities());

        foreach ([
            'FiscalYear::acc_fiscal_year_view', 'FiscalYear::acc_fiscal_year_manage',
            'AccountingPeriod::acc_period_view', 'AccountingPeriod::acc_period_close',
            'AccountType::acc_account_type_view', 'AccountType::acc_account_type_manage',
            'AccountGroup::acc_account_group_view', 'AccountGroup::acc_account_group_manage',
            'LedgerAccount::acc_account_view', 'LedgerAccount::acc_account_manage',
            'JournalEntry::acc_journal_view', 'JournalEntry::acc_journal_create', 'JournalEntry::acc_journal_post', 'JournalEntry::acc_journal_reverse',
        ] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function test_install_adds_the_five_base_types_and_can_run_again(): void
    {
        $this->artisan('accounts:install', ['--no-migrate' => true])->assertSuccessful();
        $this->artisan('accounts:install', ['--no-migrate' => true])->assertSuccessful();

        $this->assertSame(['asset', 'equity', 'expense', 'income', 'liability'], AccountType::query()->orderBy('code')->pluck('category')->all());
        $this->assertSame(['debit', 'credit', 'credit', 'credit', 'debit'], array_map(
            fn (string $category): string => AccountType::query()->where('category', $category)->first()->normalBalance(),
            ['asset', 'liability', 'equity', 'income', 'expense'],
        ));
    }
}
