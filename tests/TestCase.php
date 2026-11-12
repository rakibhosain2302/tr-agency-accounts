<?php

namespace Nit\AgraniAccounts\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nit\AgraniAccounts\AccountsServiceProvider;
use Nit\AgraniAccounts\Data\JournalEntryData;
use Nit\AgraniAccounts\Data\JournalLineData;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\AccountGroup;
use Nit\AgraniAccounts\Models\AccountType;
use Nit\AgraniAccounts\Models\CashBankAccount;
use Nit\AgraniAccounts\Models\FiscalYear;
use Nit\AgraniAccounts\Services\FiscalYearService;
use Nit\AgraniAccounts\Tests\Fixtures\User;
use Nit\Master\Database\Seeders\RoleTableSeeder;
use Nit\Master\MasterServiceProvider;
use Nit\Master\Models\Branch;
use Nit\Master\Models\Role;
use Nit\Master\Support\ApiTokens;
use Nit\Master\Support\Branches;
use Nit\Master\Support\Nit;
use Nit\Master\Support\RoleSync;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * agrani-accounts on a bare Laravel app with only nit/master: no host application code.
 */
#[WithMigration]
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [MasterServiceProvider::class, AccountsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('nit.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('nit.api.enabled', true);

        // sqlite in memory by default; MySQL / MariaDB (with the CHECK constraints) when a database is named:
        // ACCOUNTS_TEST_DB_DATABASE=nit_accounts_testing ACCOUNTS_TEST_DB_USERNAME=… ACCOUNTS_TEST_DB_PASSWORD=… vendor/bin/phpunit
        if (env('ACCOUNTS_TEST_DB_DATABASE')) {
            $app['config']->set('database.default', 'mysql');
            $app['config']->set('database.connections.mysql', array_merge($app['config']->get('database.connections.mysql'), [
                'host' => env('ACCOUNTS_TEST_DB_HOST', '127.0.0.1'),
                'port' => env('ACCOUNTS_TEST_DB_PORT', '3306'),
                'database' => env('ACCOUNTS_TEST_DB_DATABASE'),
                'username' => env('ACCOUNTS_TEST_DB_USERNAME', 'root'),
                'password' => env('ACCOUNTS_TEST_DB_PASSWORD', ''),
            ]));
        } else {
            $app['config']->set('database.default', 'testing');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['rolepermission' => [], 'ipermissions' => []]);
        (new RoleTableSeeder)->run();
        Branches::flush();
    }

    protected function superAdmin(): User
    {
        return $this->user(Nit::superAdminRole());
    }

    /** @param  list<string>  $abilities  "Group::ability" or "Group::*" */
    protected function userWith(array $abilities): User
    {
        $role = Role::query()->create(['uuid' => (string) Str::uuid(), 'title' => 'Role '.Str::random(6), 'alias' => 'role_'.Str::lower(Str::random(8))]);
        [$keys, $unknown] = RoleSync::expand($abilities);
        $this->assertSame([], $unknown, 'Unknown abilities: '.implode(', ', $unknown));
        RoleSync::apply($role, $keys);

        return $this->user($role->id);
    }

    protected function user(int $roleId): User
    {
        return User::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => 'Person '.Str::random(5),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => Hash::make('secret-password'),
            'role_id' => $roleId,
        ]);
    }

    protected function branch(string $name, ?string $code = null): Branch
    {
        return Branch::query()->create(['name' => $name, 'code' => $code ?? strtoupper(substr($name, 0, 3)), 'active' => true]);
    }

    protected function inBranches(User $user, Branch ...$branches): User
    {
        $user->branches()->sync(array_map(fn (Branch $branch): int => $branch->id, $branches));
        Branches::flush();

        return $user;
    }

    /** @return array<string, string> */
    protected function bearer(User $user): array
    {
        [, $plain] = ApiTokens::issue($user, 'test');

        return ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    }

    protected function fiscalYear(string $name = 'FY 2026-27', string $start = '2026-07-01', string $end = '2027-06-30'): FiscalYear
    {
        return app(FiscalYearService::class)->create($name, $start, $end);
    }

    /**
     * A small chart of accounts: cash and bank (asset), receivable (asset, control), payable (liability, control),
     * sales (income), expense.
     *
     * @return array{cash: Account, bank: Account, receivable: Account, payable: Account, sales: Account, expense: Account}
     */
    protected function chart(): array
    {
        $types = [];
        foreach (['asset', 'liability', 'equity', 'income', 'expense'] as $category) {
            $types[$category] = AccountType::query()->create(['code' => $category, 'name' => ucfirst($category), 'category' => $category, 'is_system' => true]);
        }

        $assets = AccountGroup::query()->create(['account_type_id' => $types['asset']->id, 'code' => '1', 'name' => 'Assets']);
        $liabilities = AccountGroup::query()->create(['account_type_id' => $types['liability']->id, 'code' => '2', 'name' => 'Liabilities']);
        $income = AccountGroup::query()->create(['account_type_id' => $types['income']->id, 'code' => '4', 'name' => 'Income']);
        $expenses = AccountGroup::query()->create(['account_type_id' => $types['expense']->id, 'code' => '5', 'name' => 'Expenses']);

        return [
            'cash' => Account::query()->create(['account_group_id' => $assets->id, 'code' => '1010', 'name' => 'Cash in hand']),
            'bank' => Account::query()->create(['account_group_id' => $assets->id, 'code' => '1020', 'name' => 'Bank']),
            'receivable' => Account::query()->create(['account_group_id' => $assets->id, 'code' => '1200', 'name' => 'Receivable', 'is_control' => true, 'control_kind' => 'receivable']),
            'payable' => Account::query()->create(['account_group_id' => $liabilities->id, 'code' => '2100', 'name' => 'Payable', 'is_control' => true, 'control_kind' => 'payable']),
            'sales' => Account::query()->create(['account_group_id' => $income->id, 'code' => '4010', 'name' => 'Sales']),
            'expense' => Account::query()->create(['account_group_id' => $expenses->id, 'code' => '5010', 'name' => 'Office expense']),
        ];
    }

    /** A cash or bank account over a chart account; branch null = shared. */
    protected function cashBank(Account $account, ?Branch $branch = null, string $kind = 'cash'): CashBankAccount
    {
        return CashBankAccount::query()->create(['account_id' => $account->id, 'code' => strtoupper($kind).'-'.$account->code, 'name' => $account->name, 'kind' => $kind, 'branch_id' => $branch?->id]);
    }

    /** A balanced cash sale for the branch: Dr cash / Cr sales. */
    protected function saleData(Branch $branch, string $amount = '1000.00', string $date = '2026-08-15', ?array $chart = null): JournalEntryData
    {
        $chart ??= $this->chart();

        return new JournalEntryData($date, [
            JournalLineData::debit($chart['cash']->id, $amount),
            JournalLineData::credit($chart['sales']->id, $amount),
        ], $branch->id, narration: 'Cash sale');
    }
}
