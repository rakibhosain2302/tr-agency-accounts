<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Services\LedgerService;
use Nit\Master\Attributes\Permission;

/**
 * Customer ledger (receivable side) and supplier ledger (payable side) of a party: generic parties, the side
 * comes from the control accounts. Limited to the user's branches.
 */
class PartyLedgerController extends Controller
{
    public function __construct(private LedgerService $ledgers) {}

    #[Permission('acc_customer_ledger_view', 'View customer ledgers')]
    public function customer(Request $request, int $party): JsonResponse
    {
        return $this->ledger($request, $party, Account::RECEIVABLE);
    }

    #[Permission('acc_supplier_ledger_view', 'View supplier ledgers')]
    public function supplier(Request $request, int $party): JsonResponse
    {
        return $this->ledger($request, $party, Account::PAYABLE);
    }

    private function ledger(Request $request, int $party, string $kind): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return response()->json(['data' => $this->ledgers->partyLedger($party, $kind, $data['from'] ?? null, $data['to'] ?? null, $request->user())]);
    }
}
