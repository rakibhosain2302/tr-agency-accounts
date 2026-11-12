<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Nit\AgraniAccounts\Http\Controllers\Api\AccountGroupController;
use Nit\AgraniAccounts\Http\Controllers\Api\AccountingPeriodController;
use Nit\AgraniAccounts\Http\Controllers\Api\AccountsPayableController;
use Nit\AgraniAccounts\Http\Controllers\Api\AccountsReceivableController;
use Nit\AgraniAccounts\Http\Controllers\Api\AccountTypeController;
use Nit\AgraniAccounts\Http\Controllers\Api\CashBankAccountController;
use Nit\AgraniAccounts\Http\Controllers\Api\ExpenseController;
use Nit\AgraniAccounts\Http\Controllers\Api\FinancialReportController;
use Nit\AgraniAccounts\Http\Controllers\Api\FinancialTransactionController;
use Nit\AgraniAccounts\Http\Controllers\Api\FiscalYearController;
use Nit\AgraniAccounts\Http\Controllers\Api\IncomeController;
use Nit\AgraniAccounts\Http\Controllers\Api\JournalEntryController;
use Nit\AgraniAccounts\Http\Controllers\Api\LedgerAccountController;
use Nit\AgraniAccounts\Http\Controllers\Api\PartyLedgerController;
use Nit\AgraniAccounts\Http\Controllers\Api\PaymentController;
use Nit\AgraniAccounts\Http\Controllers\Api\ReceiptController;
use Nit\AgraniAccounts\Http\Controllers\Api\ReconciliationController;
use Nit\Master\Http\Middlewares\AuthenticateApiToken;
use Nit\Master\Support\Nit;

/*
| The accounts JSON API. By default inside nit-master's token API: /api/nit/accounts/…, on only when
| NIT_API=true, with nit-master's token, throttle and "authorization" (ability) middleware.
| config('accounts.api') can move it or give it other middleware.
*/
if (config('accounts.api.enabled') ?? Nit::config('api.enabled', false)) {
    Route::prefix(config('accounts.api.prefix') ?? trim((string) Nit::config('api.prefix', 'api/nit'), '/').'/accounts')
        ->name('accounts.api.')
        ->middleware(config('accounts.api.middleware') ?? [AuthenticateApiToken::class, 'throttle:60,1,nit-api', SubstituteBindings::class, 'authorization'])
        ->group(function (): void {
            Route::get('fiscal-years', [FiscalYearController::class, 'index'])->name('fiscal-years.index');
            Route::post('fiscal-years', [FiscalYearController::class, 'store'])->name('fiscal-years.store');
            Route::get('fiscal-years/{fiscalYear}', [FiscalYearController::class, 'show'])->whereNumber('fiscalYear')->name('fiscal-years.show');
            Route::put('fiscal-years/{fiscalYear}', [FiscalYearController::class, 'update'])->whereNumber('fiscalYear')->name('fiscal-years.update');
            Route::post('fiscal-years/{fiscalYear}/close', [FiscalYearController::class, 'close'])->whereNumber('fiscalYear')->name('fiscal-years.close');
            Route::post('fiscal-years/{fiscalYear}/reopen', [FiscalYearController::class, 'reopen'])->whereNumber('fiscalYear')->name('fiscal-years.reopen');

            Route::get('periods', [AccountingPeriodController::class, 'index'])->name('periods.index');
            Route::post('periods/{period}/close', [AccountingPeriodController::class, 'close'])->whereNumber('period')->name('periods.close');
            Route::post('periods/{period}/reopen', [AccountingPeriodController::class, 'reopen'])->whereNumber('period')->name('periods.reopen');

            Route::get('account-types', [AccountTypeController::class, 'index'])->name('account-types.index');
            Route::post('account-types', [AccountTypeController::class, 'store'])->name('account-types.store');
            Route::put('account-types/{accountType}', [AccountTypeController::class, 'update'])->whereNumber('accountType')->name('account-types.update');
            Route::delete('account-types/{accountType}', [AccountTypeController::class, 'destroy'])->whereNumber('accountType')->name('account-types.destroy');

            Route::get('account-groups', [AccountGroupController::class, 'index'])->name('account-groups.index');
            Route::post('account-groups', [AccountGroupController::class, 'store'])->name('account-groups.store');
            Route::get('account-groups/{accountGroup}', [AccountGroupController::class, 'show'])->whereNumber('accountGroup')->name('account-groups.show');
            Route::put('account-groups/{accountGroup}', [AccountGroupController::class, 'update'])->whereNumber('accountGroup')->name('account-groups.update');
            Route::delete('account-groups/{accountGroup}', [AccountGroupController::class, 'destroy'])->whereNumber('accountGroup')->name('account-groups.destroy');

            Route::get('accounts', [LedgerAccountController::class, 'index'])->name('accounts.index');
            Route::post('accounts', [LedgerAccountController::class, 'store'])->name('accounts.store');
            Route::get('accounts/{account}', [LedgerAccountController::class, 'show'])->whereNumber('account')->name('accounts.show');
            Route::put('accounts/{account}', [LedgerAccountController::class, 'update'])->whereNumber('account')->name('accounts.update');
            Route::delete('accounts/{account}', [LedgerAccountController::class, 'destroy'])->whereNumber('account')->name('accounts.destroy');

            Route::get('journal-entries', [JournalEntryController::class, 'index'])->name('journal-entries.index');
            Route::post('journal-entries', [JournalEntryController::class, 'store'])->name('journal-entries.store');
            Route::get('journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])->whereNumber('journalEntry')->name('journal-entries.show');
            Route::put('journal-entries/{journalEntry}', [JournalEntryController::class, 'update'])->whereNumber('journalEntry')->name('journal-entries.update');
            Route::delete('journal-entries/{journalEntry}', [JournalEntryController::class, 'destroy'])->whereNumber('journalEntry')->name('journal-entries.destroy');
            Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])->whereNumber('journalEntry')->name('journal-entries.post');
            Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->whereNumber('journalEntry')->name('journal-entries.reverse');

            // Cash and bank accounts.
            Route::get('cash-bank-accounts', [CashBankAccountController::class, 'index'])->name('cash-bank-accounts.index');
            Route::post('cash-bank-accounts', [CashBankAccountController::class, 'store'])->name('cash-bank-accounts.store');
            Route::get('cash-bank-accounts/{cashBankAccount}', [CashBankAccountController::class, 'show'])->whereNumber('cashBankAccount')->name('cash-bank-accounts.show');
            Route::put('cash-bank-accounts/{cashBankAccount}', [CashBankAccountController::class, 'update'])->whereNumber('cashBankAccount')->name('cash-bank-accounts.update');
            Route::delete('cash-bank-accounts/{cashBankAccount}', [CashBankAccountController::class, 'destroy'])->whereNumber('cashBankAccount')->name('cash-bank-accounts.destroy');

            // Income, expense, receipt and payment (the type comes from the route).
            foreach (['incomes' => [IncomeController::class, 'income'], 'expenses' => [ExpenseController::class, 'expense'], 'receipts' => [ReceiptController::class, 'receipt'], 'payments' => [PaymentController::class, 'payment']] as $path => [$controller, $type]) {
                Route::get($path, [$controller, 'index'])->defaults('transaction_type', $type)->name("{$path}.index");
                Route::post($path, [$controller, 'store'])->defaults('transaction_type', $type)->name("{$path}.store");
                Route::get("{$path}/{transaction}", [$controller, 'show'])->whereNumber('transaction')->name("{$path}.show");
                Route::put("{$path}/{transaction}", [$controller, 'update'])->whereNumber('transaction')->defaults('transaction_type', $type)->name("{$path}.update");
                Route::delete("{$path}/{transaction}", [$controller, 'destroy'])->whereNumber('transaction')->name("{$path}.destroy");
                Route::post("{$path}/{transaction}/post", [$controller, 'post'])->whereNumber('transaction')->name("{$path}.post");
                Route::post("{$path}/{transaction}/reverse", [$controller, 'reverse'])->whereNumber('transaction')->name("{$path}.reverse");
                if (in_array($type, ['receipt', 'payment'], true)) {
                    Route::post("{$path}/{transaction}/allocations", [$controller, 'allocate'])->whereNumber('transaction')->name("{$path}.allocate");
                    Route::delete("{$path}/{transaction}/allocations/{allocation}", [$controller, 'unallocate'])->whereNumber(['transaction', 'allocation'])->name("{$path}.unallocate");
                }
            }

            // All financial transactions, and the payment methods.
            Route::get('transactions', [FinancialTransactionController::class, 'index'])->name('transactions.index');
            Route::get('payment-methods', [FinancialTransactionController::class, 'paymentMethods'])->name('payment-methods.index');

            // Customer and supplier ledgers, accounts receivable and payable.
            Route::get('customer-ledger/{party}', [PartyLedgerController::class, 'customer'])->whereNumber('party')->name('customer-ledger');
            Route::get('supplier-ledger/{party}', [PartyLedgerController::class, 'supplier'])->whereNumber('party')->name('supplier-ledger');
            Route::get('receivables', [AccountsReceivableController::class, 'index'])->name('receivables.index');
            Route::get('payables', [AccountsPayableController::class, 'index'])->name('payables.index');

            // Reconciliation of cash / bank accounts.
            Route::get('reconciliations', [ReconciliationController::class, 'index'])->name('reconciliations.index');
            Route::post('reconciliations', [ReconciliationController::class, 'store'])->name('reconciliations.store');
            Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show'])->whereNumber('reconciliation')->name('reconciliations.show');
            Route::delete('reconciliations/{reconciliation}', [ReconciliationController::class, 'destroy'])->whereNumber('reconciliation')->name('reconciliations.destroy');
            Route::post('reconciliations/{reconciliation}/lines', [ReconciliationController::class, 'clear'])->whereNumber('reconciliation')->name('reconciliations.clear');
            Route::delete('reconciliations/{reconciliation}/lines/{journalLine}', [ReconciliationController::class, 'unclear'])->whereNumber(['reconciliation', 'journalLine'])->name('reconciliations.unclear');
            Route::post('reconciliations/{reconciliation}/complete', [ReconciliationController::class, 'complete'])->whereNumber('reconciliation')->name('reconciliations.complete');
            Route::get('cash-bank-accounts/{cashBankAccount}/uncleared-lines', [ReconciliationController::class, 'uncleared'])->whereNumber('cashBankAccount')->name('cash-bank-accounts.uncleared');

            // Financial reports (?format=json|csv|pdf).
            foreach ([
                'general-ledger' => 'generalLedger', 'trial-balance' => 'trialBalance', 'profit-and-loss' => 'profitAndLoss',
                'balance-sheet' => 'balanceSheet', 'cash-flow' => 'cashFlow', 'income' => 'income', 'expense' => 'expense',
                'customer-ledger' => 'customerLedger', 'supplier-ledger' => 'supplierLedger', 'receivables' => 'receivables',
                'payables' => 'payables', 'cash-bank' => 'cashAndBank',
            ] as $path => $method) {
                Route::get("reports/{$path}", [FinancialReportController::class, $method])->name("reports.{$path}");
            }
        });
}
