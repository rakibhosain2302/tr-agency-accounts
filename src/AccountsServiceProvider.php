<?php

namespace Nit\AgraniAccounts;

use Illuminate\Support\ServiceProvider;
use Nit\AgraniAccounts\Console\InstallCommand;
use Nit\AgraniAccounts\Contracts\EntryNumberGenerator;
use Nit\AgraniAccounts\Contracts\PaymentMethodDirectory;
use Nit\AgraniAccounts\Contracts\TransactionNumberGenerator;
use Nit\AgraniAccounts\Reports\ReportExporter;
use Nit\AgraniAccounts\Reports\ReportService;
use Nit\AgraniAccounts\Services\ChartOfAccountsService;
use Nit\AgraniAccounts\Services\FinancialTransactionService;
use Nit\AgraniAccounts\Services\FiscalYearService;
use Nit\AgraniAccounts\Services\JournalService;
use Nit\AgraniAccounts\Services\LedgerService;
use Nit\AgraniAccounts\Services\ReconciliationService;
use Nit\AgraniAccounts\Support\ConfigPaymentMethodDirectory;
use Nit\AgraniAccounts\Support\DatabaseEntryNumberGenerator;
use Nit\AgraniAccounts\Support\DatabaseTransactionNumberGenerator;

/**
 * agrani-accounts: double-entry accounting on nit/master. Registers the config, migrations, API routes,
 * services and the default journal numbering.
 */
class AccountsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/accounts.php', 'accounts');

        // A host application binds its own numbering with bind() to replace this default.
        $this->app->bindIf(EntryNumberGenerator::class, DatabaseEntryNumberGenerator::class);
        $this->app->bindIf(TransactionNumberGenerator::class, DatabaseTransactionNumberGenerator::class);
        $this->app->bindIf(PaymentMethodDirectory::class, ConfigPaymentMethodDirectory::class);

        foreach ([FiscalYearService::class, ChartOfAccountsService::class, JournalService::class, FinancialTransactionService::class, LedgerService::class, ReconciliationService::class, ReportService::class, ReportExporter::class] as $service) {
            $this->app->singletonIf($service);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'accounts');

        $this->publishes([__DIR__.'/../config/accounts.php' => config_path('accounts.php')], 'accounts-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'accounts-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }
    }
}
