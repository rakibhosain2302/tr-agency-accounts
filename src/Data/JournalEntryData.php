<?php

namespace Nit\AgraniAccounts\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * A journal entry to write: date, branch, lines and where it comes from.
 *
 * source_type / source_id point to the document behind the entry (any model); source_key makes recording
 * idempotent: JournalService::record() returns the existing entry for a key it has seen.
 */
final readonly class JournalEntryData
{
    /**
     * @param  list<JournalLineData>  $lines
     */
    public function __construct(
        public \DateTimeInterface|string $entryDate,
        public array $lines,
        public ?int $branchId = null,
        public string $entryType = 'manual',
        public ?string $reference = null,
        public ?string $narration = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?string $sourceKey = null,
    ) {}

    /**
     * @param  list<JournalLineData>  $lines
     */
    public static function forSource(Model $source, string $sourceKey, \DateTimeInterface|string $entryDate, array $lines, int $branchId, ?string $narration = null, ?string $reference = null): self
    {
        return new self($entryDate, $lines, $branchId, 'system', $reference, $narration, $source->getMorphClass(), (int) $source->getKey(), $sourceKey);
    }

    /** @param  array<string, mixed>  $input  validated request input */
    public static function fromArray(array $input): self
    {
        return new self(
            entryDate: $input['entry_date'],
            lines: array_map(fn (array $line): JournalLineData => JournalLineData::fromArray($line), array_values($input['lines'] ?? [])),
            branchId: isset($input['branch_id']) ? (int) $input['branch_id'] : null,
            entryType: $input['entry_type'] ?? 'manual',
            reference: $input['reference'] ?? null,
            narration: $input['narration'] ?? null,
        );
    }
}
