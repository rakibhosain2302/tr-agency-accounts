<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nit\AgraniAccounts\Http\Requests\AccountGroupRequest;
use Nit\AgraniAccounts\Http\Resources\AccountGroupResource;
use Nit\AgraniAccounts\Models\AccountGroup;
use Nit\AgraniAccounts\Services\ChartOfAccountsService;
use Nit\Master\Attributes\Permission;

/**
 * Account groups. GET …?tree=1 returns the whole chart of accounts as a tree (groups, sub-groups, accounts).
 */
class AccountGroupController extends Controller
{
    public function __construct(private ChartOfAccountsService $chart) {}

    #[Permission('acc_account_group_view', 'View account groups and the chart of accounts')]
    public function index(Request $request): AnonymousResourceCollection
    {
        if ($request->boolean('tree')) {
            $groups = AccountGroup::query()->whereNull('parent_id')->with($this->treeRelations(6))->orderBy('sort_order')->orderBy('code')->get();

            return AccountGroupResource::collection($groups);
        }

        return AccountGroupResource::collection(AccountGroup::query()->with('type')->orderBy('code')->get());
    }

    #[Permission('acc_account_group_view', 'View account groups and the chart of accounts')]
    public function show(AccountGroup $accountGroup): AccountGroupResource
    {
        return new AccountGroupResource($accountGroup->load(['type', 'children', 'accounts']));
    }

    #[Permission('acc_account_group_manage', 'Add, edit and delete account groups')]
    public function store(AccountGroupRequest $request): AccountGroupResource
    {
        return new AccountGroupResource($this->chart->saveGroup($request->validated())->load('type'));
    }

    #[Permission('acc_account_group_manage', 'Add, edit and delete account groups')]
    public function update(AccountGroupRequest $request, AccountGroup $accountGroup): AccountGroupResource
    {
        return new AccountGroupResource($this->chart->saveGroup($request->validated(), $accountGroup)->load('type'));
    }

    #[Permission('acc_account_group_manage', 'Add, edit and delete account groups')]
    public function destroy(AccountGroup $accountGroup): Response
    {
        $this->chart->deleteGroup($accountGroup);

        return response()->noContent();
    }

    /** @return list<string> eager loads for a tree of $depth levels */
    private function treeRelations(int $depth): array
    {
        $relations = ['type', 'accounts'];
        $path = '';
        for ($level = 1; $level <= $depth; $level++) {
            $path .= ($path === '' ? '' : '.').'children';
            $relations[] = $path.'.type';
            $relations[] = $path.'.accounts';
        }

        return $relations;
    }
}
