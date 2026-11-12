<?php

/*
| agrani-accounts (nit/agrani-accounts): double-entry accounting.
| Publish with: php artisan vendor:publish --tag=accounts-config
|
| The package depends only on Laravel and nit/master (users, roles, permissions, branches).
| Anything else of the host application is reached through this file or through the package's contracts.
*/

return [

    /*
    | Parties (customers, suppliers, employees …) of journal lines on control accounts. Give the host
    | application's table to get a foreign key (e.g. "core_parties"); null = party_id is stored without one.
    | Read when the migrations run and when lines are validated.
    */
    'parties' => [
        'table' => env('ACCOUNTS_PARTIES_TABLE'),
        // Column of that table shown as the party's name in reports (null = party numbers only).
        'name_column' => 'name',
    ],

    /*
    | ISO code of the base currency: debit and credit of journal lines are in it. Lines may also carry a
    | transaction currency, rate and amount (fc_debit / fc_credit).
    */
    'base_currency' => env('ACCOUNTS_BASE_CURRENCY', 'BDT'),

    /*
    | Journal numbers, given when an entry is posted (drafts have none), per branch and financial year.
    | Tokens: {branch} (branch code, else id), {year} (start year of the financial year), {number} (padded).
    | Bind Nit\AgraniAccounts\Contracts\EntryNumberGenerator to use the host application's own numbering.
    */
    'numbering' => [
        'format' => 'JV-{branch}-{year}-{number}',
        'padding' => 5,
    ],

    /*
    | Numbers of income, expense, receipt and payment transactions, given when they are posted, per branch,
    | type and financial year. Same tokens as journal numbers.
    */
    'transactions' => [
        'numbering' => [
            'income' => 'INC-{branch}-{year}-{number}',
            'expense' => 'EXP-{branch}-{year}-{number}',
            'receipt' => 'RCV-{branch}-{year}-{number}',
            'payment' => 'PAY-{branch}-{year}-{number}',
        ],
        'padding' => 5,
    ],

    /*
    | Payment methods (code => name, and whether a cheque / transaction number is required). A host
    | application can bind Nit\AgraniAccounts\Contracts\PaymentMethodDirectory to keep them elsewhere.
    */
    'payment_methods' => [
        'cash' => ['name' => 'Cash', 'requires_reference' => false],
        'bank_transfer' => ['name' => 'Bank transfer', 'requires_reference' => true],
        'cheque' => ['name' => 'Cheque', 'requires_reference' => true],
        'card' => ['name' => 'Card', 'requires_reference' => true],
        'mobile_banking' => ['name' => 'Mobile banking', 'requires_reference' => true],
    ],

    /*
    | Reports: the age limits (days overdue) of the receivables and payables aging buckets.
    */
    'reports' => [
        'aging_days' => [30, 60, 90],
    ],

    /*
    | Financial years: new years get monthly periods unless asked otherwise.
    */
    'fiscal_years' => [
        'monthly_periods' => true,
    ],

    /*
    | The JSON API. By default it sits inside nit-master's token API (/api/nit/accounts/…, on only when
    | NIT_API=true) with its token, throttle and "authorization" middleware.
    */
    'api' => [
        'enabled' => null,       // null = follow nit-master's NIT_API
        'prefix' => null,        // null = "<nit-master api prefix>/accounts"
        'middleware' => null,    // null = nit-master's API middleware + "authorization"
    ],

    /*
    | Journal posting and reversal are written to nit-master's activity log, with the entry's branch.
    */
    'log_activity' => true,

];
