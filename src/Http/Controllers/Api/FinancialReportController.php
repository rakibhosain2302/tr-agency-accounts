<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nit\AgraniAccounts\Http\Requests\ReportRequest;
use Nit\AgraniAccounts\Reports\ReportExporter;
use Nit\AgraniAccounts\Reports\ReportService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Financial reports from posted journal entries, as JSON (default), CSV (?format=csv) or PDF (?format=pdf).
 * Branch filters are limited to the branches the user sees.
 */
class FinancialReportController extends Controller
{
    /** @var array<string, array{0: string, 1: string}> */
    public static $permissions = [
        'generalLedger' => ['acc_report_ledger', 'View the general ledger and trial balance'],
        'trialBalance' => ['acc_report_ledger', 'View the general ledger and trial balance'],
        'profitAndLoss' => ['acc_report_statements', 'View the profit and loss, balance sheet and cash flow'],
        'balanceSheet' => ['acc_report_statements', 'View the profit and loss, balance sheet and cash flow'],
        'cashFlow' => ['acc_report_statements', 'View the profit and loss, balance sheet and cash flow'],
        'income' => ['acc_report_income_expense', 'View the income and expense reports'],
        'expense' => ['acc_report_income_expense', 'View the income and expense reports'],
        'customerLedger' => ['acc_report_parties', 'View customer, supplier, receivable and payable reports'],
        'supplierLedger' => ['acc_report_parties', 'View customer, supplier, receivable and payable reports'],
        'receivables' => ['acc_report_parties', 'View customer, supplier, receivable and payable reports'],
        'payables' => ['acc_report_parties', 'View customer, supplier, receivable and payable reports'],
        'cashAndBank' => ['acc_report_cash_bank', 'View the cash and bank report'],
    ];

    public function __construct(private ReportService $reports, private ReportExporter $exporter) {}

    public function generalLedger(ReportRequest $request): Response|JsonResponse
    {
        return $this->respond($request, 'generalLedger');
    }

    public function trialBalance(ReportRequest $request): Response|JsonResponse
    {
        return $this->respond($request, 'trialBalance');
    }

    public function profitAndLoss(ReportRequest $request): Response|JsonResponse
    {
        return $this->respond($request, 'profitAndLoss');
    }

    public function balanceSheet(ReportRequest $request): Response|JsonResponse
    {
        return $this->respond($request, 'balanceSheet');
    }

    public function cashFlow(ReportRequest $request): Response|JsonResponse
    {
        return $this->respond($request, 'cashFlow');
    }

    public function income(ReportRequest $request): Response|JsonResponse
    {
        return $this->respond($request, 'income');
    }

    public function expense(ReportRequest $request): Response|JsonResponse
    {
        return $this->respond($request, 'expense');
    }

    public function customerLedger(ReportRequest $request): Response|JsonResponse
    {
        return $this->respond($request, 'customerLedger');
    }

    public function supplierLedger(ReportRequest $request): Response|JsonResponse
    {
        return $this->respond($request, 'supplierLedger');
    }

    public function receivables(ReportRequest $request): Response|JsonResponse
    {
        return $this->respond($request, 'receivables');
    }

    public function payables(ReportRequest $request): Response|JsonResponse
    {
        return $this->respond($request, 'payables');
    }

    public function cashAndBank(ReportRequest $request): Response|JsonResponse
    {
        return $this->respond($request, 'cashAndBank');
    }

    private function respond(ReportRequest $request, string $report): Response|JsonResponse
    {
        return $this->exporter->respond($this->reports->{$report}($request->filters()), $request->reportFormat());
    }
}
