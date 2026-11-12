# nit/agrani-accounts

Double-entry accounting for the Agrani ERP, as an independent Laravel package. It depends only on `nit/master` and Laravel. Namespace `Nit\AgraniAccounts`; tables are prefixed `acc_`.

## Install

```bash
composer require nit/agrani-accounts:@dev      # path repository next to nit-master
php artisan migrate
php artisan accounts:install                   # the five base account types
```

## What it does

| Area | Models / services |
|---|---|
| Fiscal years and periods (closing, reopening) | `FiscalYear`, `AccountingPeriod`, `FiscalYearService` |
| Chart of accounts: types, groups, accounts, receivable / payable **control accounts** (`control_kind`) | `AccountType`, `AccountGroup`, `Account`, `ChartOfAccountsService` |
| Journal entries: draft → posted → reversed; balanced lines only; numbered per branch and year | `JournalEntry`, `JournalLine`, `JournalService` (`createDraft`, `post`, `record`, `reverse`) |
| Income, expense, receipt and payment transactions, with allocations of receipts / payments to invoices / bills | `FinancialTransaction`, `Allocation`, `FinancialTransactionService` (`record`, `post`, `reverse`, `allocate`, `unallocate`, `outstanding`) |
| Party ledgers, balances and open items (customers = receivable, suppliers = payable) | `LedgerService` (`partyLedger`, `partyBalance`, `openItems`) |
| Cash and bank accounts, bank reconciliation | `CashBankAccount`, `ReconciliationService` |
| Reports (json / csv / pdf) | `Reports\ReportService`, `ReportFilters`, `ReportExporter` |

Rules the package enforces:

- An entry posts only if its debits equal its credits.
- A line is a debit or a credit, never both and never negative.
- A posted entry is never changed: it is reversed by a mirror entry.
- Every posting from another module carries a unique `source_key`, so posting twice is a no-op.
- Closed periods refuse postings.
- Records are scoped to the user's branches (`BranchScoped`).

## Integrating another module

Do not write to `acc_*` tables. Call the services with a `source_key`:

```php
app(JournalService::class)->record(new JournalEntryData(/* date, branch, lines, source_type, source_id, source_key */));
app(FinancialTransactionService::class)->record(new FinancialTransactionData(/* type income|expense|receipt|payment … */));
```

Contracts a host application may bind: `PaymentMethodDirectory` (which payment methods exist), `EntryNumberGenerator`, `TransactionNumberGenerator`. Events: `JournalEntryPosted`, `JournalEntryReversed`, `FinancialTransactionPosted`, `FinancialTransactionReversed`, `AllocationRecorded`, `AllocationReversed`.

## API

`/api/nit/accounts/...`: 93 endpoints with Bearer token, throttle and `authorization` middleware, enabled by `ACCOUNTS_API` (or `NIT_API`). The full list is in the application's `docs/api/api-reference.md`. Reports: `/reports/{general-ledger|trial-balance|profit-and-loss|balance-sheet|cash-flow|income|expense|customer-ledger|supplier-ledger|receivables|payables|cash-bank}?from&to&fiscal_year_id&branch_ids[]&format=json|csv|pdf`.

## Configuration (`config/accounts.php`)

`parties` (the host's party table, default `core_parties`), `base_currency`, `numbering`, `transactions`, `payment_methods`, `reports`, `fiscal_years`, `api`, `log_activity`.

## Tests

154 tests on MariaDB:

```bash
ACCOUNTS_TEST_DB_DATABASE=nit_accounts_testing ACCOUNTS_TEST_DB_USERNAME=… ACCOUNTS_TEST_DB_PASSWORD=… vendor/bin/phpunit
```
