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
use Nit\AgraniAccounts\Http\Requests\JournalEntryRequest;
use Nit\AgraniAccounts\Http\Requests\ReverseJournalEntryRequest;
use Nit\AgraniAccounts\Http\Resources\JournalEntryResource;
use Nit\AgraniAccounts\Models\JournalEntry;
use Nit\AgraniAccounts\Models\JournalLine;
use Nit\AgraniAccounts\Services\JournalService;
use Nit\Master\Attributes\Permission;
use Nit\Master\Support\Table;

/**
 * Journal entries of the branches the user sees (other branches answer 404).
 */
class JournalEntryController extends Controller
{
    public function __construct(private JournalService $journals) {}

    #[Permission('acc_journal_view', 'View journal entries')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in([JournalEntry::DRAFT, JournalEntry::POSTED, JournalEntry::REVERSED])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'source_type' => ['nullable', 'string', 'max:191'],
            'source_id' => ['nullable', 'integer'],
        ]);

        $query = JournalEntry::query()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('entry_date', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('entry_date', '<=', $to))
            ->when($request->query('source_type'), fn ($q, $type) => $q->where('source_type', $type))
            ->when($request->integer('source_id'), fn ($q, $id) => $q->where('source_id', $id));

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $this->search($query, $term);
        }

        // With the lines, so each entry carries its total (total_debit = total_credit).
        return JournalEntryResource::collection(Table::make($query->with('lines'), $request)->sortable(['entry_date', 'entry_no', 'id'], '-id')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    #[Permission('acc_journal_view', 'View journal entries')]
    public function show(JournalEntry $journalEntry): JournalEntryResource
    {
        return new JournalEntryResource($journalEntry->load(['lines.account', 'reversal']));
    }

    /** A draft; with "post": true it is posted at once (needs the posting ability too). */
    #[Permission('acc_journal_create', 'Write journal drafts')]
    public function store(JournalEntryRequest $request): JsonResponse
    {
        if ($request->boolean('post')) {
            Gate::authorize('acc_journal_post');
        }

        // Writing and posting happen together: a journal that cannot be posted leaves no draft behind.
        $entry = DB::transaction(function () use ($request): JournalEntry {
            $entry = $this->journals->createDraft($request->entryData());

            return $request->boolean('post') ? $this->journals->post($entry) : $entry;
        });

        return (new JournalEntryResource($entry->load('lines.account')))->response()->setStatusCode(201);
    }

    #[Permission('acc_journal_create', 'Write journal drafts')]
    public function update(JournalEntryRequest $request, JournalEntry $journalEntry): JournalEntryResource
    {
        return new JournalEntryResource($this->journals->updateDraft($journalEntry, $request->entryData())->load('lines.account'));
    }

    #[Permission('acc_journal_create', 'Write journal drafts')]
    public function destroy(JournalEntry $journalEntry): Response
    {
        $this->journals->deleteDraft($journalEntry);

        return response()->noContent();
    }

    #[Permission('acc_journal_post', 'Post journal entries')]
    public function post(JournalEntry $journalEntry): JournalEntryResource
    {
        return new JournalEntryResource($this->journals->post($journalEntry)->load('lines.account'));
    }

    #[Permission('acc_journal_reverse', 'Reverse posted journal entries')]
    public function reverse(ReverseJournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        $reversal = $this->journals->reverse($journalEntry, $request->validated('reversal_date'), $request->validated('narration'));

        return (new JournalEntryResource($reversal->load('lines.account')))->response()->setStatusCode(201);
    }

    /**
     * Columns a search (?q=) looks in: everything the list shows (number, date, type, status, reference,
     * narration); the entry's total is found too, written with or without thousands commas ("15,000").
     *
     * @var list<string>
     */
    public const SEARCHED = ['entry_no', 'entry_date', 'entry_type', 'status', 'reference', 'narration'];

    /** @param  Builder<JournalEntry>  $query */
    protected function search(Builder $query, string $term): void
    {
        $term = mb_substr($term, 0, 100);
        $like = fn (string $text): string => '%'.addcslashes($text, '%_\\').'%';
        $amount = preg_match('/^[\d,]+(\.\d+)?$/', $term) === 1 ? str_replace(',', '', $term) : null;

        $query->where(function (Builder $where) use ($term, $like, $amount): void {
            foreach (self::SEARCHED as $column) {
                $where->orWhere($column, 'like', $like($term));
            }
            if ($amount !== null) {
                $where->orWhereIn('id', JournalLine::query()->select('journal_entry_id')->groupBy('journal_entry_id')
                    ->havingRaw('CAST(SUM(debit) AS CHAR) LIKE ?', [$like($amount)]));
            }
        });
    }
}
