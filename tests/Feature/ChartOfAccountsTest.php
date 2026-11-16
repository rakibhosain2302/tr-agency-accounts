<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\AccountGroup;
use Nit\AgraniAccounts\Models\AccountType;
use Nit\AgraniAccounts\Services\ChartOfAccountsService;
use Nit\AgraniAccounts\Services\JournalService;
use Nit\AgraniAccounts\Tests\TestCase;

class ChartOfAccountsTest extends TestCase
{
    private function chartService(): ChartOfAccountsService
    {
        return app(ChartOfAccountsService::class);
    }

    public function test_groups_form_a_tree_of_one_category(): void
    {
        $chart = $this->chart();
        $assets = AccountGroup::query()->where('code', '1')->firstOrFail();
        $currentAssetType = AccountType::query()->create(['code' => 'current_asset', 'name' => 'Current asset', 'category' => 'asset']);

        $current = $this->chartService()->saveGroup(['parent_id' => $assets->id, 'account_type_id' => $currentAssetType->id, 'code' => '11', 'name' => 'Current assets']);
        $bank = $this->chartService()->saveGroup(['parent_id' => $current->id, 'account_type_id' => $currentAssetType->id, 'code' => '111', 'name' => 'Bank']);

        $this->assertSame([$assets->id, $current->id, $bank->id], $assets->descendantIds());
        $this->assertSame('asset', $chart['cash']->category());

        $this->expectException(AccountingException::class);
        $this->chartService()->saveGroup(['parent_id' => $assets->id, 'account_type_id' => AccountType::query()->where('category', 'income')->value('id'), 'code' => '12', 'name' => 'Wrong category']);
    }

    public function test_a_new_group_without_a_code_gets_the_next_four_digit_number(): void
    {
        $types = collect(['asset', 'expense'])
            ->mapWithKeys(fn (string $category): array => [$category => AccountType::query()->create(['code' => $category, 'name' => ucfirst($category), 'category' => $category])->id]);
        $save = fn (string $category, ?AccountGroup $parent = null, ?string $code = null): AccountGroup => $this->chartService()
            ->saveGroup(['parent_id' => $parent?->id, 'account_type_id' => $types[$category], 'code' => $code, 'name' => 'Group']);

        // One running number for every group, whatever its parent or category.
        $assets = $save('asset');
        $cash = $save('asset', $assets);
        $this->assertSame(['0001', '0002', '0003', '0004'], [$assets->code, $cash->code, $save('expense')->code, $save('asset', $cash)->code]);

        // A code typed in is kept; the numbers go on after the highest one.
        $this->assertSame('0010', $save('asset', $assets, '0010')->code);
        $this->assertSame('0011', $save('asset')->code);

        // Editing keeps the code.
        $this->assertSame('0002', $this->chartService()->saveGroup(['parent_id' => $assets->id, 'account_type_id' => $types['asset'], 'name' => 'Cash and bank'], $cash)->code);
    }

    public function test_a_new_account_without_a_code_gets_the_next_four_digit_number(): void
    {
        $type = AccountType::query()->create(['code' => 'asset', 'name' => 'Assets', 'category' => 'asset']);
        $assets = $this->chartService()->saveGroup(['account_type_id' => $type->id, 'name' => 'Assets']);
        $cash = $this->chartService()->saveGroup(['parent_id' => $assets->id, 'account_type_id' => $type->id, 'name' => 'Cash']);
        $save = fn (AccountGroup $group, ?string $code = null): Account => $this->chartService()->saveAccount(['account_group_id' => $group->id, 'code' => $code, 'name' => 'Account']);

        // Accounts count on their own, apart from the groups.
        $this->assertSame(['0001', '0002', '0003'], [$save($cash)->code, $save($assets)->code, $save($cash)->code]);
        $this->assertSame('5110', $save($assets, '5110')->code);
        $kept = $save($cash);
        $this->assertSame('5111', $kept->code);

        $this->assertSame('5111', $this->chartService()->saveAccount(['account_group_id' => $assets->id, 'name' => 'Renamed'], $kept)->code);
    }

    public function test_a_group_cannot_go_below_itself(): void
    {
        $this->chart();
        $assets = AccountGroup::query()->where('code', '1')->firstOrFail();
        $child = $this->chartService()->saveGroup(['parent_id' => $assets->id, 'account_type_id' => $assets->account_type_id, 'code' => '11', 'name' => 'Child']);

        $this->expectException(AccountingException::class);
        $this->chartService()->saveGroup(['parent_id' => $child->id, 'account_type_id' => $assets->account_type_id, 'code' => '1', 'name' => 'Assets'], $assets);
    }

    public function test_things_in_use_are_not_deleted(): void
    {
        $chart = $this->chart();
        $branch = $this->branch('Dhaka');
        $this->fiscalYear();
        app(JournalService::class)->record($this->saleData($branch, chart: $chart));

        foreach ([
            fn () => $this->chartService()->deleteAccount($chart['cash']),
            fn () => $this->chartService()->deleteGroup(AccountGroup::query()->where('code', '1')->firstOrFail()),
            fn () => $this->chartService()->deleteType(AccountType::query()->where('category', 'asset')->firstOrFail()),
        ] as $delete) {
            try {
                $delete();
                $this->fail('Something in use was deleted.');
            } catch (AccountingException $exception) {
                $this->assertSame('invalid_chart', $exception->reason);
            }
        }

        $this->chartService()->deleteAccount($chart['expense']);
        $this->assertNull(Account::query()->find($chart['expense']->id));
    }

    public function test_an_account_with_lines_cannot_move_to_another_category(): void
    {
        $chart = $this->chart();
        $this->fiscalYear();
        app(JournalService::class)->record($this->saleData($this->branch('Dhaka'), chart: $chart));

        $this->expectException(AccountingException::class);
        $this->chartService()->saveAccount(['account_group_id' => AccountGroup::query()->where('code', '5')->value('id')], $chart['cash']);
    }
}
