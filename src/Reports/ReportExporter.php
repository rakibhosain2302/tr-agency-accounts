<?php

namespace Nit\AgraniAccounts\Reports;

use Illuminate\Http\JsonResponse;
use Nit\Master\Support\Pdf;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report as JSON, as CSV for Excel (UTF-8 with BOM, so Excel shows Bangla and other letters correctly), or as
 * PDF through nit-master's Pdf (by default the browser's "Save as PDF", which also shapes Bangla text; a PDF file
 * when dompdf is installed and nit.pdf.engine = dompdf).
 */
final class ReportExporter
{
    public const FORMATS = ['json', 'csv', 'pdf'];

    public function respond(Report $report, string $format): Response|JsonResponse
    {
        return match ($format) {
            'csv' => $this->csv($report),
            'pdf' => Pdf::download('accounts::report', ['report' => $report], $this->filename($report, 'pdf')),
            default => response()->json(['data' => $report->toArray()]),
        };
    }

    public function csv(Report $report): StreamedResponse
    {
        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$report->title]);
            foreach ($report->filters as $key => $value) {
                fputcsv($out, [$key, is_array($value) ? implode(' ', $value) : (string) $value]);
            }

            foreach ($report->sections as $section) {
                fputcsv($out, []);
                fputcsv($out, [$section->title]);
                fputcsv($out, array_values($section->columns));
                foreach ($section->rows as $row) {
                    fputcsv($out, $this->cells($section, $row));
                }
                if ($section->totals !== null) {
                    fputcsv($out, $this->cells($section, $section->totals));
                }
            }

            fputcsv($out, []);
            foreach ($report->summary as $key => $value) {
                fputcsv($out, [$key, is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value)]);
            }
            fclose($out);
        }, $this->filename($report, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Cells in the order of the section's columns. Text that a spreadsheet would run as a formula is quoted.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function cells(ReportSection $section, array $row): array
    {
        return array_map(function (string $key) use ($row): string {
            $value = (string) ($row[$key] ?? '');

            return preg_match('/^[=+\-@\t\r]/', $value) && ! is_numeric($value) ? "'".$value : $value;
        }, array_keys($section->columns));
    }

    private function filename(Report $report, string $extension): string
    {
        return $report->key.'-'.($report->filters['from'] ?? '').'-'.($report->filters['to'] ?? '').'.'.$extension;
    }
}
