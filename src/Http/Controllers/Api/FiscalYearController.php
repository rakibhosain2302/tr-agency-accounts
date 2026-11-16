<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Nit\AgraniAccounts\Http\Requests\FiscalYearRequest;
use Nit\AgraniAccounts\Http\Resources\FiscalYearResource;
use Nit\AgraniAccounts\Models\FiscalYear;
use Nit\AgraniAccounts\Services\FiscalYearService;
use Nit\Master\Attributes\Permission;

class FiscalYearController extends Controller
{
    public function __construct(private FiscalYearService $years) {}

    #[Permission('acc_fiscal_year_view', 'View financial years and periods')]
    public function index(): AnonymousResourceCollection
    {
        return FiscalYearResource::collection(FiscalYear::query()->orderByDesc('start_date')->get());
    }

    #[Permission('acc_fiscal_year_view', 'View financial years and periods')]
    public function show(FiscalYear $fiscalYear): FiscalYearResource
    {
        return new FiscalYearResource($fiscalYear->load('periods'));
    }

    #[Permission('acc_fiscal_year_manage', 'Create, close and reopen financial years')]
    public function store(FiscalYearRequest $request): FiscalYearResource
    {
        $data = $request->validated();
        $year = $this->years->create($data['name'], $data['start_date'], $data['end_date'], isset($data['monthly_periods']) ? $request->boolean('monthly_periods') : null);

        return new FiscalYearResource($year);
    }

    #[Permission('acc_fiscal_year_manage', 'Create, close and reopen financial years')]
    public function update(FiscalYearRequest $request, FiscalYear $fiscalYear): FiscalYearResource
    {
        $data = $request->validated();

        return new FiscalYearResource($this->years->update($fiscalYear, $data['name'], $data['start_date'] ?? null, $data['end_date'] ?? null,
            isset($data['monthly_periods']) ? $request->boolean('monthly_periods') : null));
    }

    #[Permission('acc_fiscal_year_manage', 'Create, close and reopen financial years')]
    public function close(FiscalYear $fiscalYear): FiscalYearResource
    {
        return new FiscalYearResource($this->years->close($fiscalYear)->load('periods'));
    }

    #[Permission('acc_fiscal_year_manage', 'Create, close and reopen financial years')]
    public function reopen(FiscalYear $fiscalYear): FiscalYearResource
    {
        return new FiscalYearResource($this->years->reopen($fiscalYear)->load('periods'));
    }
}
