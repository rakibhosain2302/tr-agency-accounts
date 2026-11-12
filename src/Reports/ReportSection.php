<?php

namespace Nit\AgraniAccounts\Reports;

/**
 * One table of a report: columns (key => label), rows (arrays keyed by column) and an optional totals row.
 */
final class ReportSection
{
    /**
     * @param  array<string, string>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals
     */
    public function __construct(
        public string $title,
        public array $columns,
        public array $rows = [],
        public ?array $totals = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['title' => $this->title, 'columns' => $this->columns, 'rows' => $this->rows, 'totals' => $this->totals];
    }
}
