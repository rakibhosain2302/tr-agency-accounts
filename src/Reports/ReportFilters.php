<?php

namespace Nit\AgraniAccounts\Reports;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Nit\AgraniAccounts\Exceptions\AccountingException;
use Nit\AgraniAccounts\Models\Account;
use Nit\AgraniAccounts\Models\AccountGroup;
use Nit\AgraniAccounts\Models\FiscalYear;
use Nit\Master\Support\Branches;

/**
 * The filters of a report, resolved: the date range (from a financial year, or given, or the current year),
 * the branches (only those the user may see), and the accounts (given, or every account below a group).
 */
final readonly class ReportFilters
{
    /**
     * @param  list<int>|null  $branchIds  null = every branch
     * @param  list<int>  $accountIds  [] = every account
     * @param  list<int>  $cashBankAccountIds  [] = every cash / bank account
     */
    public function __construct(
        public string $from,
        public string $to,
        public ?int $fiscalYearId = null,
        public ?array $branchIds = null,
        public array $accountIds = [],
        public ?int $partyId = null,
        public array $cashBankAccountIds = [],
    ) {}

    /**
     * @param  array{from?: ?string, to?: ?string, fiscal_year_id?: ?int, branch_ids?: ?list<int>, account_ids?: ?list<int>, account_group_id?: ?int, party_id?: ?int, cash_bank_account_ids?: ?list<int>}  $input
     */
    public static function resolve(array $input, ?Authenticatable $user = null): self
    {
        $year = isset($input['fiscal_year_id']) ? FiscalYear::query()->findOrFail($input['fiscal_year_id']) : null;

        $to = Carbon::parse($input['to'] ?? $year?->end_date ?? now())->toDateString();
        $from = $input['from'] ?? $year?->start_date ?? FiscalYear::query()->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $to)->value('start_date') ?? Carbon::parse($to)->startOfYear();
        $from = Carbon::parse($from)->toDateString();

        if ($from > $to) {
            throw AccountingException::invalidReport(__('The report starts after it ends.'));
        }
        if ($year !== null && ($from < $year->start_date->toDateString() || $to > $year->end_date->toDateString())) {
            throw AccountingException::invalidReport(__('The dates must be inside financial year :name.', ['name' => $year->name]));
        }

        $accountIds = array_map('intval', $input['account_ids'] ?? []);
        if (! empty($input['account_group_id'])) {
            $group = AccountGroup::query()->findOrFail($input['account_group_id']);
            $inGroup = Account::query()->whereIn('account_group_id', $group->descendantIds())->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $accountIds = $accountIds === [] ? ($inGroup ?: [0]) : (array_values(array_intersect($accountIds, $inGroup)) ?: [0]);
        }

        return new self(
            from: $from,
            to: $to,
            fiscalYearId: $year?->id,
            branchIds: self::branches($input['branch_ids'] ?? null, $user),
            accountIds: $accountIds,
            partyId: isset($input['party_id']) ? (int) $input['party_id'] : null,
            cashBankAccountIds: array_map('intval', $input['cash_bank_account_ids'] ?? []),
        );
    }

    /**
     * The start of the financial year that contains the report's start, from where income and expense count
     * (they begin again at zero each financial year). Without a financial year: the report's start.
     */
    public function profitYearStart(): string
    {
        $start = FiscalYear::query()->whereDate('start_date', '<=', $this->from)->whereDate('end_date', '>=', $this->from)->value('start_date');

        return $start !== null ? Carbon::parse($start)->toDateString() : $this->from;
    }

    /** @return array<string, mixed> for the report's header and the JSON */
    public function toArray(): array
    {
        return array_filter([
            'from' => $this->from,
            'to' => $this->to,
            'fiscal_year_id' => $this->fiscalYearId,
            'branch_ids' => $this->branchIds,
            'account_ids' => $this->accountIds ?: null,
            'party_id' => $this->partyId,
            'cash_bank_account_ids' => $this->cashBankAccountIds ?: null,
        ], fn ($value): bool => $value !== null);
    }

    /**
     * Requested branches limited to those the user sees; null = no limit (every branch).
     *
     * @param  list<int>|null  $requested
     * @return list<int>|null
     */
    private static function branches(?array $requested, ?Authenticatable $user): ?array
    {
        $visible = null;
        if ($user !== null && Branches::enabled()) {
            $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
            $visible = (! $isSuperAdmin && Branches::assignedIds($user) === []) ? [] : Branches::visibleIds($user);
        }

        $requested = $requested !== null && $requested !== [] ? array_map('intval', $requested) : null;

        if ($visible === null) {
            return $requested;
        }

        $ids = $requested === null ? $visible : array_values(array_intersect($requested, $visible));

        return $ids === [] ? [0] : $ids;
    }
}
