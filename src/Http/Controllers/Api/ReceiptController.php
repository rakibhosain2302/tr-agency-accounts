<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Nit\AgraniAccounts\Models\FinancialTransaction;

/**
 * Receipts: money in from a party, allocated to its income on credit (in part or in full).
 */
class ReceiptController extends TransactionController
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'index' => ['acc_receipt_view', 'View receipts'],
        'show' => ['acc_receipt_view', 'View receipts'],
        'store' => ['acc_receipt_create', 'Write receipts (drafts)'],
        'update' => ['acc_receipt_create', 'Write receipts (drafts)'],
        'destroy' => ['acc_receipt_create', 'Write receipts (drafts)'],
        'post' => ['acc_receipt_post', 'Post receipts'],
        'reverse' => ['acc_receipt_reverse', 'Reverse posted receipts'],
        'allocate' => ['acc_receipt_allocate', 'Allocate receipts to what is outstanding'],
        'unallocate' => ['acc_receipt_allocate', 'Allocate receipts to what is outstanding'],
    ];

    protected function type(): string
    {
        return FinancialTransaction::RECEIPT;
    }
}
