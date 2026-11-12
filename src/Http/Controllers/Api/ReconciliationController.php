<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nit\AgraniAccounts\Http\Requests\ReconciliationRequest;
use Nit\AgraniAccounts\Http\Resources\ReconciliationResource;
use Nit\AgraniAccounts\Models\CashBankAccount;
use Nit\AgraniAccounts\Models\Reconciliation;
use Nit\AgraniAccounts\Services\ReconciliationService;
use Nit\Master\Attributes\Permission;

/**
 * Reconciliation of cash / bank accounts with their statements (accounts of the user's branches and shared ones).
 */
class ReconciliationController extends Controller
{
    public function __construct(private ReconciliationService $reconciliations) {}

    #[Permission('acc_reconciliation_view', 'View reconciliations')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['cash_bank_account_id' => ['nullable', 'integer']]);
        $accounts = CashBankAccount::query()->visibleTo($request->user())->pluck('id');

        return ReconciliationResource::collection(Reconciliation::query()->whereIn('cash_bank_account_id', $accounts)
            ->when($request->integer('cash_bank_account_id'), fn ($q, $id) => $q->where('cash_bank_account_id', $id))
            ->orderByDesc('statement_date')->get());
    }

    #[Permission('acc_reconciliation_view', 'View reconciliations')]
    public function show(Request $request, int $reconciliation): ReconciliationResource
    {
        return new ReconciliationResource($this->find($request, $reconciliation)->load('lines'));
    }

    /** Posted lines of a cash / bank account that no reconciliation has cleared yet. */
    #[Permission('acc_reconciliation_view', 'View reconciliations')]
    public function uncleared(Request $request, int $cashBankAccount): JsonResponse
    {
        $data = $request->validate(['up_to' => ['nullable', 'date']]);
        $account = CashBankAccount::query()->visibleTo($request->user())->findOrFail($cashBankAccount);

        $lines = $this->reconciliations->unclearedLines($account, $data['up_to'] ?? null)
            ->orderBy('e.entry_date')->orderBy('l.id')
            ->get(['l.id as journal_line_id', 'e.entry_date', 'e.entry_no', 'e.narration', 'l.debit', 'l.credit']);

        return response()->json(['data' => $lines]);
    }

    #[Permission('acc_reconciliation_manage', 'Reconcile cash and bank accounts')]
    public function store(ReconciliationRequest $request): ReconciliationResource
    {
        $data = $request->validated();
        $account = CashBankAccount::query()->visibleTo($request->user())->findOrFail($data['cash_bank_account_id']);

        return new ReconciliationResource($this->reconciliations->start($account, $data['statement_date'], $data['statement_balance'], $data['notes'] ?? null));
    }

    #[Permission('acc_reconciliation_manage', 'Reconcile cash and bank accounts')]
    public function clear(Request $request, int $reconciliation): ReconciliationResource
    {
        $data = $request->validate(['journal_line_ids' => ['required', 'array', 'min:1', 'max:1000'], 'journal_line_ids.*' => ['integer']]);

        return new ReconciliationResource($this->reconciliations->clear($this->find($request, $reconciliation), $data['journal_line_ids'])->load('lines'));
    }

    #[Permission('acc_reconciliation_manage', 'Reconcile cash and bank accounts')]
    public function unclear(Request $request, int $reconciliation, int $journalLine): ReconciliationResource
    {
        return new ReconciliationResource($this->reconciliations->unclear($this->find($request, $reconciliation), $journalLine)->load('lines'));
    }

    #[Permission('acc_reconciliation_manage', 'Reconcile cash and bank accounts')]
    public function complete(Request $request, int $reconciliation): ReconciliationResource
    {
        return new ReconciliationResource($this->reconciliations->complete($this->find($request, $reconciliation))->load('lines'));
    }

    #[Permission('acc_reconciliation_manage', 'Reconcile cash and bank accounts')]
    public function destroy(Request $request, int $reconciliation): Response
    {
        $this->reconciliations->discard($this->find($request, $reconciliation));

        return response()->noContent();
    }

    private function find(Request $request, int $id): Reconciliation
    {
        return Reconciliation::query()->whereIn('cash_bank_account_id', CashBankAccount::query()->visibleTo($request->user())->pluck('id'))->findOrFail($id);
    }
}
