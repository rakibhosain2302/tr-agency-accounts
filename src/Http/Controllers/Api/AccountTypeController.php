<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nit\AgraniAccounts\Http\Requests\AccountTypeRequest;
use Nit\AgraniAccounts\Http\Resources\AccountTypeResource;
use Nit\AgraniAccounts\Models\AccountType;
use Nit\AgraniAccounts\Services\ChartOfAccountsService;
use Nit\Master\Attributes\Permission;

class AccountTypeController extends Controller
{
    #[Permission('acc_account_type_view', 'View account types')]
    public function index(): AnonymousResourceCollection
    {
        return AccountTypeResource::collection(AccountType::query()->orderBy('category')->orderBy('code')->get());
    }

    #[Permission('acc_account_type_manage', 'Add, edit and delete account types')]
    public function store(AccountTypeRequest $request): AccountTypeResource
    {
        return new AccountTypeResource(AccountType::query()->create($request->validated()));
    }

    #[Permission('acc_account_type_manage', 'Add, edit and delete account types')]
    public function update(AccountTypeRequest $request, AccountType $accountType): AccountTypeResource
    {
        $accountType->update($request->validated());

        return new AccountTypeResource($accountType);
    }

    #[Permission('acc_account_type_manage', 'Add, edit and delete account types')]
    public function destroy(AccountType $accountType, ChartOfAccountsService $chart): Response
    {
        $chart->deleteType($accountType);

        return response()->noContent();
    }
}
