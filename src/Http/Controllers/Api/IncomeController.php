<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Nit\AgraniAccounts\Models\FinancialTransaction;

/**
 * Income: paid at once into cash / bank, or on credit to a party (accounts receivable).
 */
class IncomeController extends TransactionController
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'index' => ['acc_income_view', 'View incomes'],
        'show' => ['acc_income_view', 'View incomes'],
        'store' => ['acc_income_create', 'Write incomes (drafts)'],
        'update' => ['acc_income_create', 'Write incomes (drafts)'],
        'destroy' => ['acc_income_create', 'Write incomes (drafts)'],
        'post' => ['acc_income_post', 'Post incomes'],
        'reverse' => ['acc_income_reverse', 'Reverse posted incomes'],
    ];

    protected function type(): string
    {
        return FinancialTransaction::INCOME;
    }
}
