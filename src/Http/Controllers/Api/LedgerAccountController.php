<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nit\AgraniAccounts\Http\Requests\LedgerAccountRequest;
use Nit\AgraniAccounts\Http\Resources\AccountResource;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Services\ChartOfAccountsService;
use Nit\Master\Attributes\Permission;
use Nit\Master\Support\Table;

/**
 * The accounts of the chart of accounts. Named "LedgerAccount" so its abilities have a group of their own.
 */
class LedgerAccountController extends Controller
{
    public function __construct(private ChartOfAccountsService $chart) {}

    #[Permission('acc_account_view', 'View the chart of accounts')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['account_group_id' => ['nullable', 'integer'], 'active' => ['nullable', 'boolean']]);

        $query = Account::query()->with('group.type')
            ->when($request->integer('account_group_id'), fn ($q, $id) => $q->where('account_group_id', $id))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')));

        return AccountResource::collection(Table::make($query, $request)->search(['code', 'name'])->sortable(['code', 'name'], 'code')->paginate(min(200, max(1, $request->integer('per_page', 50)))));
    }

    #[Permission('acc_account_view', 'View the chart of accounts')]
    public function show(Account $account): AccountResource
    {
        return new AccountResource($account->load('group.type'));
    }

    #[Permission('acc_account_manage', 'Add, edit and delete accounts')]
    public function store(LedgerAccountRequest $request): AccountResource
    {
        return new AccountResource($this->chart->saveAccount($request->accountData())->load('group.type'));
    }

    #[Permission('acc_account_manage', 'Add, edit and delete accounts')]
    public function update(LedgerAccountRequest $request, Account $account): AccountResource
    {
        return new AccountResource($this->chart->saveAccount($request->accountData(), $account)->load('group.type'));
    }

    #[Permission('acc_account_manage', 'Add, edit and delete accounts')]
    public function destroy(Account $account): Response
    {
        $this->chart->deleteAccount($account);

        return response()->noContent();
    }
}
