<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Http\Requests\CashBankAccountRequest;
use Nit\AgraniAccounts\Http\Resources\CashBankAccountResource;
use Nit\AgraniAccounts\Models\CashBankAccount;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\Master\Attributes\Permission;

/**
 * Cash, bank and mobile-wallet accounts. The list shows the shared ones and those of the user's branches.
 */
class CashBankAccountController extends Controller
{
    #[Permission('acc_cash_bank_view', 'View cash and bank accounts')]
    public function index(Request $request): AnonymousResourceCollection
    {
        return CashBankAccountResource::collection(CashBankAccount::query()->visibleTo($request->user())->with('account')
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('code')->get());
    }

    #[Permission('acc_cash_bank_view', 'View cash and bank accounts')]
    public function show(Request $request, int $cashBankAccount): CashBankAccountResource
    {
        return new CashBankAccountResource(CashBankAccount::query()->visibleTo($request->user())->with('account')->findOrFail($cashBankAccount));
    }

    #[Permission('acc_cash_bank_manage', 'Add, edit and delete cash and bank accounts')]
    public function store(CashBankAccountRequest $request): CashBankAccountResource
    {
        return new CashBankAccountResource(CashBankAccount::query()->create($this->data($request))->load('account'));
    }

    #[Permission('acc_cash_bank_manage', 'Add, edit and delete cash and bank accounts')]
    public function update(CashBankAccountRequest $request, int $cashBankAccount): CashBankAccountResource
    {
        $cashBankAccount = CashBankAccount::query()->visibleTo($request->user())->findOrFail($cashBankAccount);
        $cashBankAccount->update($this->data($request));

        return new CashBankAccountResource($cashBankAccount->load('account'));
    }

    #[Permission('acc_cash_bank_manage', 'Add, edit and delete cash and bank accounts')]
    public function destroy(Request $request, int $cashBankAccount): Response
    {
        $cashBankAccount = CashBankAccount::query()->visibleTo($request->user())->findOrFail($cashBankAccount);
        if (FinancialTransaction::query()->withoutGlobalScopes()->where('cash_bank_account_id', $cashBankAccount->id)->exists() || $cashBankAccount->reconciliations()->exists()) {
            throw AccountingException::invalidChart(__('Cash / bank account :code is in use; make it inactive instead.', ['code' => $cashBankAccount->code]));
        }

        $cashBankAccount->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function data(CashBankAccountRequest $request): array
    {
        $data = $request->validated();
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        return $data;
    }
}
