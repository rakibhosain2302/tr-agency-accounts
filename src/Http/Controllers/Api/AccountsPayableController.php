<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Services\LedgerService;
use Nit\AgraniAccounts\Support\Decimal;
use Nit\Master\Attributes\Permission;

/**
 * Accounts payable: expense on credit not fully paid, with outstanding amount and days overdue.
 */
class AccountsPayableController extends Controller
{
    #[Permission('acc_payable_view', 'View accounts payable')]
    public function index(Request $request, LedgerService $ledgers): JsonResponse
    {
        $data = $request->validate(['party_id' => ['nullable', 'integer'], 'as_of' => ['nullable', 'date']]);
        $items = $ledgers->openItems(Account::PAYABLE, $data['party_id'] ?? null, $data['as_of'] ?? null);

        return response()->json(['data' => $items, 'total_outstanding' => Decimal::sum(array_column($items, 'outstanding'))]);
    }
}
