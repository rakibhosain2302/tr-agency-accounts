<?php

namespace Nit\AgraniAccounts\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Nit\AgraniAccounts\Contracts\PaymentMethodDirectory;
use Nit\AgraniAccounts\Http\Requests\FinancialTransactionRequest;
use Nit\AgraniAccounts\Http\Requests\ReverseJournalEntryRequest;
use Nit\AgraniAccounts\Http\Resources\AllocationResource;
use Nit\AgraniAccounts\Http\Resources\FinancialTransactionResource;
use Nit\AgraniAccounts\Models\Allocation;
use Nit\AgraniAccounts\Models\FinancialTransaction;
use Nit\AgraniAccounts\Services\FinancialTransactionService;
use Nit\Master\Support\Table;

/**
 * Shared actions of the income, expense, receipt and payment endpoints. Each subclass names its type and
 * declares its abilities in static $permissions (nit-master reads them for these inherited actions).
 * Transactions of branches the user does not see answer 404.
 */
abstract class TransactionController extends Controller
{
    /**
     * Columns a search (?q=) looks in: everything the lists show (number, dates, amount, status, type) and the
     * reference, narration and payment reference (cheque no., bKash TrxID …). It also finds the party by name
     * (config accounts.parties), the cash / bank account by name or code, and the payment method by name.
     *
     * @var list<string>
     */
    public const SEARCHED = ['transaction_no', 'transaction_date', 'due_date', 'total_amount', 'status', 'transaction_type', 'payment_method', 'reference', 'narration', 'payment_reference'];

    public function __construct(protected FinancialTransactionService $transactions) {}

    abstract protected function type(): string;

    /** Ability of posting, e.g. "acc_income_post" (also needed to write and post at once). */
    protected function ability(string $action): string
    {
        return 'acc_'.$this->type().'_'.$action;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in([FinancialTransaction::DRAFT, FinancialTransaction::POSTED, FinancialTransaction::REVERSED])],
            'party_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = FinancialTransaction::query()->ofType($this->type())
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->integer('party_id'), fn ($q, $id) => $q->where('party_id', $id))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('transaction_date', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('transaction_date', '<=', $to));

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $this->search($query, $term);
        }

        return FinancialTransactionResource::collection(Table::make($query, $request)->sortable(['transaction_date', 'transaction_no', 'id'], '-id')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function show(int $transaction): FinancialTransactionResource
    {
        return new FinancialTransactionResource($this->find($transaction)->load(['lines', 'allocationsMade', 'allocationsReceived']));
    }

    /**
     * A draft. With "post": true it is posted at once (posting ability needed); a receipt / payment posted at
     * once may also carry "allocations" (allocating ability needed). All of it happens or none of it.
     */
    public function store(FinancialTransactionRequest $request): JsonResponse
    {
        $post = $request->boolean('post');
        $allocations = $request->validated('allocations') ?? [];
        if ($post) {
            Gate::authorize($this->ability('post'));
        }
        if ($allocations !== []) {
            abort_unless($post, 422, __('Allocations can be given only when the transaction is posted at once.'));
            Gate::authorize($this->ability('allocate'));
        }

        $transaction = DB::transaction(function () use ($request, $post, $allocations): FinancialTransaction {
            $transaction = $this->transactions->createDraft($request->transactionData());
            if (! $post) {
                return $transaction;
            }

            $transaction = $this->transactions->post($transaction);
            foreach ($allocations as $allocation) {
                $this->transactions->allocate($transaction, $this->findTarget((int) $allocation['target_id']), $allocation['amount'], $transaction->transaction_date);
            }

            return $transaction;
        });

        return (new FinancialTransactionResource($transaction->load(['lines', 'allocationsMade'])))->response()->setStatusCode(201);
    }

    public function update(FinancialTransactionRequest $request, int $transaction): FinancialTransactionResource
    {
        return new FinancialTransactionResource($this->transactions->updateDraft($this->find($transaction), $request->transactionData()));
    }

    public function destroy(int $transaction): Response
    {
        $this->transactions->deleteDraft($this->find($transaction));

        return response()->noContent();
    }

    public function post(int $transaction): FinancialTransactionResource
    {
        return new FinancialTransactionResource($this->transactions->post($this->find($transaction)));
    }

    public function reverse(ReverseJournalEntryRequest $request, int $transaction): FinancialTransactionResource
    {
        return new FinancialTransactionResource($this->transactions->reverse($this->find($transaction), $request->validated('reversal_date'), $request->validated('narration'))->load(['allocationsMade', 'allocationsReceived']));
    }

    /** Receipts / payments: settle (part of) an income / expense on credit. */
    public function allocate(Request $request, int $transaction): JsonResponse
    {
        $data = $request->validate([
            'target_id' => ['required', 'integer'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'allocation_date' => ['nullable', 'date'],
        ]);

        $allocation = $this->transactions->allocate($this->find($transaction), $this->findTarget((int) $data['target_id']), $data['amount'], $data['allocation_date'] ?? null);

        return (new AllocationResource($allocation))->response()->setStatusCode(201);
    }

    public function unallocate(int $transaction, int $allocation): AllocationResource
    {
        $settlement = $this->find($transaction);
        $record = Allocation::query()->where('settlement_id', $settlement->id)->findOrFail($allocation);

        return new AllocationResource($this->transactions->unallocate($record));
    }

    protected function find(int $id): FinancialTransaction
    {
        return FinancialTransaction::query()->ofType($this->type())->findOrFail($id);
    }

    /** The income / expense a receipt / payment settles (also limited to the user's branches). */
    /**
     * Keeps the transactions that have the words (?q=) in a column of SEARCHED, in the name of their party,
     * cash / bank account or payment method. An amount may be written with thousands commas ("15,000").
     *
     * @param  Builder<FinancialTransaction>  $query
     */
    protected function search(Builder $query, string $term): void
    {
        $term = mb_substr($term, 0, 100);
        $like = fn (string $text): string => '%'.addcslashes($text, '%_\\').'%';
        $amount = preg_match('/^[\d,]+(\.\d+)?$/', $term) === 1 ? str_replace(',', '', $term) : null;
        $methods = collect(app(PaymentMethodDirectory::class)->all())
            ->filter(fn (array $method, string $code): bool => mb_stripos($method['name'], $term) !== false || mb_stripos($code, $term) !== false)
            ->keys()->all();
        $parties = config('accounts.parties.table');
        $partyName = config('accounts.parties.name_column', 'name');

        $query->where(function (Builder $where) use ($term, $like, $amount, $methods, $parties, $partyName): void {
            foreach (self::SEARCHED as $column) {
                $where->orWhere($column, 'like', $like($term));
            }
            if ($amount !== null) {
                $where->orWhere('total_amount', 'like', $like($amount));
            }
            $where->orWhereHas('cashBankAccount', fn (Builder $account) => $account->where('name', 'like', $like($term))->orWhere('code', 'like', $like($term)));
            if ($methods !== []) {
                $where->orWhereIn('payment_method', $methods);
            }
            if ($parties && $partyName) {
                $where->orWhereIn('party_id', DB::table($parties)->select('id')->where($partyName, 'like', $like($term)));
            }
        });
    }

    protected function findTarget(int $id): FinancialTransaction
    {
        return FinancialTransaction::query()->findOrFail($id);
    }
}
