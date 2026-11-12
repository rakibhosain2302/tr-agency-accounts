<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Nit\AgraniAccounts\Models\FinancialTransaction;

/**
 * Expenses: paid at once from cash / bank, or on credit from a party (accounts payable).
 */
class ExpenseController extends TransactionController
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'index' => ['acc_expense_view', 'View expenses'],
        'show' => ['acc_expense_view', 'View expenses'],
        'store' => ['acc_expense_create', 'Write expenses (drafts)'],
        'update' => ['acc_expense_create', 'Write expenses (drafts)'],
        'destroy' => ['acc_expense_create', 'Write expenses (drafts)'],
        'post' => ['acc_expense_post', 'Post expenses'],
        'reverse' => ['acc_expense_reverse', 'Reverse posted expenses'],
    ];

    protected function type(): string
    {
        return FinancialTransaction::EXPENSE;
    }
}
