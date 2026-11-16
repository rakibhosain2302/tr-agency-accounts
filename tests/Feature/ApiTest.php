<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\AgraniAccounts\Services\JournalService;
use Nit\AgraniAccounts\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The JSON API inside nit-master's token API: tokens, abilities, validation and accounting errors.
 */
class ApiTest extends TestCase
{
    private const API = '/api/nit/accounts';

    public function test_requests_without_a_token_are_refused(): void
    {
        $this->getJson(self::API.'/fiscal-years')->assertUnauthorized();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpointsWithTheirAbility(): array
    {
        return [
            'years' => ['get', '/fiscal-years'],
            'periods' => ['get', '/periods'],
            'types' => ['get', '/account-types'],
            'groups' => ['get', '/account-groups'],
            'accounts' => ['get', '/accounts'],
            'journals' => ['get', '/journal-entries'],
            'new year' => ['post', '/fiscal-years'],
            'new journal' => ['post', '/journal-entries'],
        ];
    }

    #[DataProvider('endpointsWithTheirAbility')]
    public function test_every_endpoint_needs_its_ability(string $method, string $path): void
    {
        $this->json($method, self::API.$path, [], $this->bearer($this->userWith(['Profile::*'])))->assertUnauthorized();
        $this->json($method, self::API.$path, [], $this->bearer($this->superAdmin()))->assertStatus($method === 'get' ? 200 : 422);
    }

    public function test_setting_up_a_year_and_a_chart_of_accounts(): void
    {
        $headers = $this->bearer($this->userWith(['FiscalYear::*', 'AccountingPeriod::*', 'AccountType::*', 'AccountGroup::*', 'LedgerAccount::*']));

        $year = $this->postJson(self::API.'/fiscal-years', ['name' => 'FY 2026-27', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30'], $headers)
            ->assertCreated()->assertJsonCount(12, 'data.periods')->json('data');
        $this->postJson(self::API.'/fiscal-years', ['name' => 'Overlap', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31'], $headers)
            ->assertUnprocessable()->assertJsonPath('reason', 'invalid_fiscal_year');
        $this->putJson(self::API.'/fiscal-years/'.$year['id'], ['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'monthly_periods' => false], $headers)
            ->assertOk()->assertJsonPath('data.start_date', '2026-01-01')->assertJsonPath('data.monthly_periods', false)->assertJsonCount(1, 'data.periods');
        $year = $this->putJson(self::API.'/fiscal-years/'.$year['id'], ['name' => 'FY 2026-27', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'monthly_periods' => true], $headers)
            ->assertOk()->assertJsonPath('data.monthly_periods', true)->assertJsonCount(12, 'data.periods')->json('data');
        $this->postJson(self::API.'/periods/'.$year['periods'][0]['id'].'/close', [], $headers)->assertOk()->assertJsonPath('data.status', 'closed');

        $type = $this->postJson(self::API.'/account-types', ['code' => 'current_asset', 'name' => 'Current asset', 'category' => 'asset'], $headers)
            ->assertCreated()->assertJsonPath('data.normal_balance', 'debit')->json('data');
        $group = $this->postJson(self::API.'/account-groups', ['account_type_id' => $type['id'], 'code' => '1', 'name' => 'Current assets'], $headers)->assertCreated()->json('data');
        $this->postJson(self::API.'/account-groups', ['parent_id' => $group['id'], 'account_type_id' => $type['id'], 'name' => 'Cash and bank'], $headers)->assertCreated()->assertJsonPath('data.code', '0002');
        $this->postJson(self::API.'/accounts', ['account_group_id' => $group['id'], 'code' => '1010', 'name' => 'Cash', 'currency_code' => 'bdt'], $headers)
            ->assertCreated()->assertJsonPath('data.currency_code', 'BDT')->assertJsonPath('data.category', 'asset');
        $this->postJson(self::API.'/accounts', ['account_group_id' => $group['id'], 'code' => '1010', 'name' => 'Duplicate'], $headers)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson(self::API.'/accounts', ['account_group_id' => $group['id'], 'name' => 'Bank'], $headers)->assertCreated()->assertJsonPath('data.code', '1011');

        $this->getJson(self::API.'/account-groups?tree=1', $headers)->assertOk()
            ->assertJsonPath('data.0.code', '1')
            ->assertJsonPath('data.0.accounts.0.code', '1010');
    }

    public function test_writing_posting_and_reversing_a_journal(): void
    {
        $branch = $this->branch('Dhaka');
        $accounts = $this->chart();
        $this->fiscalYear();
        $headers = $this->bearer($this->inBranches($this->userWith(['JournalEntry::*']), $branch));
        $lines = [['account_id' => $accounts['expense']->id, 'debit' => '250.50'], ['account_id' => $accounts['cash']->id, 'credit' => '250.50']];

        $draft = $this->postJson(self::API.'/journal-entries', ['entry_date' => '2026-08-01', 'branch_id' => $branch->id, 'narration' => 'Stationery', 'lines' => $lines], $headers)
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.entry_no', null)->assertJsonPath('data.total_debit', '250.50')->json('data');

        $this->putJson(self::API.'/journal-entries/'.$draft['id'], ['entry_date' => '2026-08-02', 'branch_id' => $branch->id, 'lines' => $lines], $headers)->assertOk()->assertJsonPath('data.entry_date', '2026-08-02');

        $posted = $this->postJson(self::API.'/journal-entries/'.$draft['id'].'/post', [], $headers)->assertOk()
            ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.entry_no', 'JV-DHA-2026-00001')->json('data');

        $this->putJson(self::API.'/journal-entries/'.$posted['id'], ['entry_date' => '2026-08-02', 'branch_id' => $branch->id, 'lines' => $lines], $headers)
            ->assertUnprocessable()->assertJsonPath('reason', 'not_draft');
        $this->deleteJson(self::API.'/journal-entries/'.$posted['id'], [], $headers)->assertUnprocessable();

        $this->postJson(self::API.'/journal-entries/'.$posted['id'].'/reverse', ['reversal_date' => '2026-08-05'], $headers)->assertCreated()
            ->assertJsonPath('data.entry_type', 'reversal')->assertJsonPath('data.reversal_of_id', $posted['id'])
            ->assertJsonPath('data.lines.0.credit', '250.50');
        $this->getJson(self::API.'/journal-entries/'.$posted['id'], $headers)->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->getJson(self::API.'/journal-entries?status=posted', $headers)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_unbalanced_journal_is_refused_with_its_reason_and_leaves_nothing(): void
    {
        $branch = $this->branch('Dhaka');
        $accounts = $this->chart();
        $this->fiscalYear();

        $this->postJson(self::API.'/journal-entries', [
            'entry_date' => '2026-08-01', 'branch_id' => $branch->id, 'post' => true,
            'lines' => [['account_id' => $accounts['expense']->id, 'debit' => '100'], ['account_id' => $accounts['cash']->id, 'credit' => '90']],
        ], $this->bearer($this->superAdmin()))->assertUnprocessable()->assertJsonPath('reason', 'unbalanced');

        $this->assertSame(0, JournalEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_writing_and_posting_at_once_needs_the_posting_ability(): void
    {
        $branch = $this->branch('Dhaka');
        $accounts = $this->chart();
        $this->fiscalYear();
        $writer = $this->inBranches($this->userWith(['JournalEntry::acc_journal_view', 'JournalEntry::acc_journal_create']), $branch);
        $payload = ['entry_date' => '2026-08-01', 'branch_id' => $branch->id, 'post' => true, 'lines' => [['account_id' => $accounts['expense']->id, 'debit' => '10'], ['account_id' => $accounts['cash']->id, 'credit' => '10']]];

        $this->postJson(self::API.'/journal-entries', $payload, $this->bearer($writer))->assertForbidden();
        $draft = $this->postJson(self::API.'/journal-entries', ['post' => false] + $payload, $this->bearer($writer))->assertCreated()->json('data');
        $this->postJson(self::API.'/journal-entries/'.$draft['id'].'/post', [], $this->bearer($writer))->assertUnauthorized();
        $this->postJson(self::API.'/journal-entries/'.$draft['id'].'/reverse', [], $this->bearer($writer))->assertUnauthorized();
    }

    public function test_journals_of_another_branch_are_not_found_and_branch_is_checked(): void
    {
        $dhaka = $this->branch('Dhaka');
        $sylhet = $this->branch('Sylhet');
        $accounts = $this->chart();
        $this->fiscalYear();
        $foreign = app(JournalService::class)->record($this->saleData($sylhet, chart: $accounts));
        $headers = $this->bearer($this->inBranches($this->userWith(['JournalEntry::*']), $dhaka));

        $this->getJson(self::API.'/journal-entries/'.$foreign->id, $headers)->assertNotFound();
        $this->postJson(self::API.'/journal-entries/'.$foreign->id.'/reverse', [], $headers)->assertNotFound();
        $this->getJson(self::API.'/journal-entries', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::API.'/journal-entries', ['entry_date' => '2026-08-01', 'branch_id' => $sylhet->id, 'lines' => [['account_id' => $accounts['cash']->id, 'debit' => '1'], ['account_id' => $accounts['sales']->id, 'credit' => '1']]], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_line_input_is_validated(): void
    {
        $branch = $this->branch('Dhaka');
        $this->chart();

        $this->postJson(self::API.'/journal-entries', ['entry_date' => 'not a date', 'branch_id' => $branch->id, 'lines' => [['account_id' => 999999, 'debit' => '1.005']]], $this->bearer($this->superAdmin()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entry_date', 'lines', 'lines.0.account_id', 'lines.0.debit']);
    }
}
