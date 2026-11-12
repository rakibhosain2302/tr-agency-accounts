<?php

namespace Nit\AgraniAccounts\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Nit\AgraniAccounts\Models\AccountType;

/**
 * php artisan accounts:install [--no-migrate]
 *
 * Runs the migrations and adds the five base account types (one per accounting category). Safe to run again.
 * The chart of accounts itself is the business's own and is not created here.
 */
class InstallCommand extends Command
{
    protected $signature = 'accounts:install {--no-migrate : Do not run the migrations}';

    protected $description = 'Install agrani-accounts: migrations and the five base account types';

    /** @var array<string, string> category => name of its base type */
    public const BASE_TYPES = [
        'asset' => 'Assets',
        'liability' => 'Liabilities',
        'equity' => 'Equity',
        'income' => 'Income',
        'expense' => 'Expenses',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('branches')) {
            $this->error('nit/master is not installed yet: run php artisan nit:install first.');

            return self::FAILURE;
        }

        if (! $this->option('no-migrate')) {
            $this->call('migrate', ['--force' => true]);
        }

        foreach (self::BASE_TYPES as $category => $name) {
            AccountType::query()->firstOrCreate(['code' => $category], ['name' => $name, 'category' => $category, 'is_system' => true]);
        }

        $this->info('agrani-accounts is installed. Create a financial year and the chart of accounts, then give roles the accounting abilities.');

        return self::SUCCESS;
    }
}
