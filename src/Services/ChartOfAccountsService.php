<?php

namespace Nit\AgraniAccounts\Services;

use Illuminate\Support\Facades\DB;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\AccountGroup;
use Nit\AgraniAccounts\Models\AccountType;

/**
 * Rules of the chart of accounts that a form cannot check alone: a group's category matches its parent's,
 * the tree has no loops, and nothing in use is deleted (deactivate it instead).
 */
final class ChartOfAccountsService
{
    /** @param  array<string, mixed>  $data */
    public function saveGroup(array $data, ?AccountGroup $group = null): AccountGroup
    {
        return DB::transaction(function () use ($data, $group): AccountGroup {
            $type = AccountType::query()->findOrFail($data['account_type_id']);
            $parentId = $data['parent_id'] ?? null;

            if ($parentId !== null) {
                $parent = AccountGroup::query()->with('type')->findOrFail($parentId);
                if ($parent->type->category !== $type->category) {
                    throw AccountingException::invalidChart(__('Group :code is :category; a group below it must be :category too.', ['code' => $parent->code, 'category' => $parent->type->category]));
                }
                if ($group !== null && in_array((int) $parentId, $group->descendantIds(), true)) {
                    throw AccountingException::invalidChart(__('A group cannot be placed below itself.'));
                }
            }

            if ($group !== null && (int) $group->account_type_id !== (int) $type->id) {
                $usedBelow = Account::query()->whereIn('account_group_id', $group->descendantIds())->whereHas('lines')->exists();
                $categoryChanges = $group->type->category !== $type->category;
                if ($categoryChanges && ($usedBelow || $group->children()->exists())) {
                    throw AccountingException::invalidChart(__('The category of a group with sub-groups or posted accounts cannot change.'));
                }
            }

            $group ??= new AccountGroup;
            $group->fill($data)->save();

            return $group->refresh();
        });
    }

    public function deleteGroup(AccountGroup $group): void
    {
        if ($group->children()->exists() || $group->accounts()->exists()) {
            throw AccountingException::invalidChart(__('Group :code still has sub-groups or accounts.', ['code' => $group->code]));
        }

        $group->delete();
    }

    /** @param  array<string, mixed>  $data */
    public function saveAccount(array $data, ?Account $account = null): Account
    {
        if ($account !== null && $account->lines()->exists()) {
            $newGroup = AccountGroup::query()->with('type')->findOrFail($data['account_group_id'] ?? $account->account_group_id);
            if ($newGroup->type->category !== $account->category()) {
                throw AccountingException::invalidChart(__('Account :code has journal lines: it cannot move to another category.', ['code' => $account->code]));
            }
        }

        $account ??= new Account;
        $account->fill($data)->save();

        return $account->refresh();
    }

    public function deleteAccount(Account $account): void
    {
        if ($account->is_system || $account->lines()->exists()) {
            throw AccountingException::invalidChart(__('Account :code is in use; make it inactive instead.', ['code' => $account->code]));
        }

        $account->delete();
    }

    public function deleteType(AccountType $type): void
    {
        if ($type->is_system || $type->groups()->exists()) {
            throw AccountingException::invalidChart(__('Account type :code is in use.', ['code' => $type->code]));
        }

        $type->delete();
    }
}
