<?php

namespace Nit\AgraniAccounts\Reports;

/**
 * A computed report: a title, the filters it was made with, sections (tables) and a summary of key figures
 * and checks. The same structure is returned as JSON and exported as CSV or PDF.
 */
final class Report
{
    /** @var list<ReportSection> */
    public array $sections = [];

    /** @var array<string, mixed> */
    public array $summary = [];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(public string $key, public string $title, public array $filters) {}

    public function section(ReportSection $section): self
    {
        $this->sections[] = $section;

        return $this;
    }

    /** @param  array<string, mixed>  $values */
    public function summarize(array $values): self
    {
        $this->summary = [...$this->summary, ...$values];

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'report' => $this->key,
            'title' => $this->title,
            'filters' => $this->filters,
            'summary' => $this->summary,
            'sections' => array_map(fn (ReportSection $section): array => $section->toArray(), $this->sections),
        ];
    }
}
