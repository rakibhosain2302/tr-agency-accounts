<?php

namespace Nit\AgraniAccounts\Contracts;

use Nit\AgraniAccounts\Models\JournalEntry;

/**
 * Gives a journal entry its number when it is posted. Called inside the posting transaction, with the
 * entry's branch, financial year and period already set. A host application may bind its own (e.g. to its
 * document-number service); the default is DatabaseEntryNumberGenerator.
 */
interface EntryNumberGenerator
{
    /**
     * @return array{sequence_no: int, entry_no: string}
     */
    public function next(JournalEntry $entry): array;
}
