<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Nit\AgraniAccounts\Http\Resources\PeriodResource;
use Nit\AgraniAccounts\Models\Period;
use Nit\AgraniAccounts\Services\FiscalYearService;
use Nit\Master\Attributes\Permission;

class AccountingPeriodController extends Controller
{
    public function __construct(private FiscalYearService $years) {}

    #[Permission('acc_period_view', 'View accounting periods')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['fiscal_year_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:open,closed']]);

        return PeriodResource::collection(Period::query()
            ->when($request->integer('fiscal_year_id'), fn ($query, $id) => $query->where('fiscal_year_id', $id))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('start_date')->get());
    }

    #[Permission('acc_period_close', 'Close and reopen accounting periods')]
    public function close(Period $period): PeriodResource
    {
        return new PeriodResource($this->years->closePeriod($period));
    }

    #[Permission('acc_period_close', 'Close and reopen accounting periods')]
    public function reopen(Period $period): PeriodResource
    {
        return new PeriodResource($this->years->reopenPeriod($period));
    }
}
