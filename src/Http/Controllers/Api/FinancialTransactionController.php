<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nit\AgraniAccounts\Contracts\PaymentMethodDirectory;
use Nit\AgraniAccounts\Http\Resources\FinancialTransactionResource;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\Master\Attributes\Permission;
use Nit\Master\Support\Table;

/**
 * Every financial transaction (income, expense, receipt, payment) of the user's branches in one list, e.g. to
 * find what a document of another module produced (source_type / source_id), and the payment methods.
 */
class FinancialTransactionController extends Controller
{
    #[Permission('acc_transaction_view', 'View all financial transactions')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['nullable', Rule::in(FinancialTransaction::TYPES)],
            'status' => ['nullable', Rule::in([FinancialTransaction::DRAFT, FinancialTransaction::POSTED, FinancialTransaction::REVERSED])],
            'party_id' => ['nullable', 'integer'],
            'source_type' => ['nullable', 'string', 'max:191'],
            'source_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = FinancialTransaction::query()
            ->when($request->query('type'), fn ($q, $type) => $q->where('transaction_type', $type))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->integer('party_id'), fn ($q, $id) => $q->where('party_id', $id))
            ->when($request->query('source_type'), fn ($q, $type) => $q->where('source_type', $type))
            ->when($request->integer('source_id'), fn ($q, $id) => $q->where('source_id', $id))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('transaction_date', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('transaction_date', '<=', $to));

        return FinancialTransactionResource::collection(Table::make($query, $request)->search(['transaction_no', 'reference', 'narration'])->sortable(['transaction_date', 'transaction_no', 'id'], '-id')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    #[Permission('acc_transaction_view', 'View all financial transactions')]
    public function paymentMethods(PaymentMethodDirectory $methods): JsonResponse
    {
        return response()->json(['data' => collect($methods->all())->map(fn (array $method, string $code): array => ['code' => $code] + $method)->values()]);
    }
}
