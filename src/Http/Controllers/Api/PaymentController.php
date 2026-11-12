<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Nit\AgraniAccounts\Models\FinancialTransaction;

/**
 * Payments: money out to a party, allocated to its expenses on credit (in part or in full).
 */
class PaymentController extends TransactionController
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'index' => ['acc_payment_view', 'View payments'],
        'show' => ['acc_payment_view', 'View payments'],
        'store' => ['acc_payment_create', 'Write payments (drafts)'],
        'update' => ['acc_payment_create', 'Write payments (drafts)'],
        'destroy' => ['acc_payment_create', 'Write payments (drafts)'],
        'post' => ['acc_payment_post', 'Post payments'],
        'reverse' => ['acc_payment_reverse', 'Reverse posted payments'],
        'allocate' => ['acc_payment_allocate', 'Allocate payments to what is outstanding'],
        'unallocate' => ['acc_payment_allocate', 'Allocate payments to what is outstanding'],
    ];

    protected function type(): string
    {
        return FinancialTransaction::PAYMENT;
    }
}
