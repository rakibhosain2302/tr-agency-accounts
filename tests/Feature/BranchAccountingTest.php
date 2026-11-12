<?php

namespace Nit\AgraniAccounts\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Nit\AgraniAccounts\Data\JournalEntryData;
use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\AgraniAccounts\Services\JournalService;
use Nit\AgraniAccounts\Tests\TestCase;
use Nit\Master\Models\Branch;

class BranchAccountingTest extends TestCase
{
    private Branch $dhaka;

    private Branch $sylhet;

    private array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dhaka = $this->branch('Dhaka');
        $this->sylhet = $this->branch('Sylhet');
        $this->accounts = $this->chart();
        $this->fiscalYear();

        // Posted by the system (console): not limited by branch.
        app(JournalService::class)->record($this->saleData($this->dhaka, chart: $this->accounts));
        app(JournalService::class)->record($this->saleData($this->sylhet, chart: $this->accounts));
    }

    public function test_staff_see_only_the_journals_of_their_branches(): void
    {
        $this->actingAs($this->inBranches($this->userWith(['JournalEntry::*']), $this->dhaka));

        $this->assertSame([$this->dhaka->id], JournalEntry::query()->pluck('branch_id')->all());
    }

    public function test_staff_of_several_branches_see_all_of_theirs(): void
    {
        $this->actingAs($this->inBranches($this->userWith(['JournalEntry::*']), $this->dhaka, $this->sylhet));

        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_a_journal_cannot_be_written_into_another_branch(): void
    {
        $this->actingAs($this->inBranches($this->userWith(['JournalEntry::*']), $this->dhaka));

        $this->expectException(AuthorizationException::class);
        app(JournalService::class)->createDraft($this->saleData($this->sylhet, chart: $this->accounts));
    }

    public function test_a_journal_gets_the_users_only_branch(): void
    {
        $this->actingAs($this->inBranches($this->userWith(['JournalEntry::*']), $this->sylhet));

        $data = $this->saleData($this->sylhet, chart: $this->accounts);
        $draft = app(JournalService::class)->createDraft(new JournalEntryData($data->entryDate, $data->lines));

        $this->assertSame($this->sylhet->id, $draft->branch_id);
    }

    public function test_branchless_staff_see_no_journal(): void
    {
        $this->actingAs($this->userWith(['JournalEntry::*']));

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_super_admin_sees_every_branch(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertSame(2, JournalEntry::query()->count());
    }
}
