{{-- A report on nit-master's A4 print page (company logo, name and address from Settings). --}}
@extends('nit::print')

@section('title', $report->title)

@php
    /** An amount ("30000.00", "-1200.50") with thousands separators; null when the value is not one (a code such as "0002" is not). */
    $money = function ($value): ?string {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! preg_match('/^(-?)(\d+)\.(\d{2})$/', (string) $value, $parts)) {
            return null;
        }

        return $parts[1].preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[2]).'.'.$parts[3];
    };
    $day = fn ($date) => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? \Illuminate\Support\Carbon::parse($date)->format('j M Y') : null;
    $from = $day($report->filters['from'] ?? null);
    $to = $day($report->filters['to'] ?? null);
    /** The balance sheet is the books on one day; the other reports cover a period. */
    $period = match (true) {
        $report->key === 'balance-sheet' && $to !== null => __('As of :date', ['date' => $to]),
        $from !== null && $to !== null => $from.' – '.$to,
        $to !== null => __('Up to :date', ['date' => $to]),
        default => '',
    };

    /** A figure: an amount, a count or a share ("12.5%"). */
    $isFigure = fn ($value): bool => $money($value) !== null || is_int($value) || (is_string($value) && preg_match('/^-?\d+(\.\d+)?%$/', $value) === 1);
    /** The columns of figures (every value in them is one): their cells and headers are aligned right. */
    $amounts = fn ($section): array => collect(array_keys($section->columns))->filter(function ($column) use ($section, $isFigure) {
        $values = collect($section->rows)->pluck($column)->push(($section->totals ?? [])[$column] ?? null)->filter(fn ($value) => $value !== null && $value !== '');

        return $values->isNotEmpty() && $values->every($isFigure);
    })->values()->all();

    /**
     * Each column's width, shared by every table so tables with the same columns line up: room for its longest
     * value (an amount or a code never breaks) and for the longest word of its heading (the heading wraps
     * between words only).
     */
    $widths = [];
    foreach ($report->sections as $section) {
        foreach ($section->columns as $column => $label) {
            $longest = collect($section->rows)->pluck($column)->push(($section->totals ?? [])[$column] ?? '')
                ->map(fn ($value) => is_scalar($value) ? mb_strlen((string) ($money($value) ?? $value)) : 8)->max();
            $word = collect(preg_split('/\s+/', (string) $label))->map(fn ($part) => mb_strlen($part))->max() ?? 0;
            $isAmount = collect($section->rows)->pluck($column)->filter(fn ($value) => $value !== null && $value !== '')->every(fn ($value) => $money($value) !== null);
            $widths[$column] = max($widths[$column] ?? 4, min(36, max((int) $longest, (int) $word + 1, $isAmount && $section->rows !== [] ? 10 : 4)));
        }
    }
    /** A wide report (many columns of amounts, like the trial balance) is printed across the page. */
    $wide = collect($report->sections)->contains(fn ($section) => count($section->columns) >= 7
        || array_sum(array_map(fn ($column) => $widths[$column] ?? 10, array_keys($section->columns))) > 90);
    $summary = collect($report->summary)->reject(fn ($value) => is_array($value));
    $isBalanceSheet = $report->key === 'balance-sheet' && $summary->has(['total_assets', 'total_liabilities', 'total_equity']);
    // The balance sheet's totals are in its check at the top.
    $rest = $isBalanceSheet ? $summary->except(['as_of', 'total_assets', 'total_liabilities', 'total_equity', 'total_liabilities_and_equity', 'balanced', 'difference']) : $summary;
@endphp

@section('subtitle')
    {{ $period }}
@endsection

@push('css')
<style>
    @if ($wide)
        @page { size: A4 landscape; margin: 12mm 12mm 14mm; }
        .sheet { max-width: 297mm !important; }
        .rp-table { font-size: 10.5px !important; }
    @endif
    .rp-check { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; margin: 14px 0 4px; padding: 10px 14px; font-size: 12px; border: 1px solid #e2e8f0; border-left: 3px solid #16a34a; border-radius: 6px; }
    .rp-check.is-off { border-left-color: #dc2626; }
    .rp-check b { font-size: 14px; font-variant-numeric: tabular-nums; }
    .rp-check .rp-ok { margin-left: auto; font-weight: 700; color: #15803d; }
    .rp-check.is-off .rp-ok { color: #b91c1c; }
    .rp-sign { color: #94a3b8; }
    .rp-section { margin: 18px 0 6px; font-size: 14px; }
    .rp-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 11px; }
    .rp-table th, .rp-table td { padding: 4px 6px; text-align: left; vertical-align: top; word-break: normal; overflow-wrap: break-word; }
    .rp-table thead th { font-size: 9.5px; line-height: 1.3; letter-spacing: .03em; text-transform: uppercase; vertical-align: bottom; white-space: normal; color: #475569; background: #f8fafc; border-bottom: 1px solid #94a3b8; }
    .rp-table tbody td { border-bottom: 1px solid #eef1f6; }
    .rp-table tfoot td { font-weight: 700; border-top: 1px solid #94a3b8; background: #f8fafc; }
    .rp-table .rp-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .rp-table .rp-neg { color: #b91c1c; }
    .rp-summary { margin-top: 16px; border-collapse: collapse; font-size: 11px; }
    .rp-summary td { padding: 3px 0; border-bottom: 1px solid #eef1f6; }
    .rp-summary td:first-child { padding-right: 24px; color: #475569; }
    .rp-summary td:last-child { text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
</style>
@endpush

@section('content')
    @if ($isBalanceSheet)
        @php $balanced = (bool) ($summary['balanced'] ?? true); @endphp
        <div class="rp-check {{ $balanced ? '' : 'is-off' }}">
            <span>{{ __('Assets') }} <b>{{ $money($summary['total_assets']) ?? $summary['total_assets'] }}</b></span>
            <span class="rp-sign">=</span>
            <span>{{ __('Liabilities') }} <b>{{ $money($summary['total_liabilities']) ?? $summary['total_liabilities'] }}</b></span>
            <span class="rp-sign">+</span>
            <span>{{ __('Equity') }} <b>{{ $money($summary['total_equity']) ?? $summary['total_equity'] }}</b></span>
            <span class="rp-ok">{{ $balanced ? '✓ '.__('Balanced') : '✗ '.__('Off by :amount', ['amount' => $money($summary['difference'] ?? '0.00') ?? ($summary['difference'] ?? '')]) }}</span>
        </div>
    @endif

    @foreach ($report->sections as $section)
        @php
            $numbers = $amounts($section);
            $share = max(1, array_sum(array_map(fn ($column) => $widths[$column] ?? 10, array_keys($section->columns))));
        @endphp
        <h3 class="rp-section">{{ $section->title }}</h3>
        <table class="rp-table">
            <colgroup>@foreach (array_keys($section->columns) as $column)<col style="width: {{ round(($widths[$column] ?? 10) / $share * 100, 2) }}%">@endforeach</colgroup>
            <thead>
                <tr>
                    @foreach ($section->columns as $column => $label)
                        <th class="{{ in_array($column, $numbers, true) ? 'rp-num' : '' }}">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($section->rows as $row)
                    <tr>
                        @foreach (array_keys($section->columns) as $column)
                            @php
                                $value = $row[$column] ?? null;
                                $shown = $money($value);
                            @endphp
                            @if ($shown !== null)
                                <td class="rp-num {{ str_starts_with($shown, '-') ? 'rp-neg' : '' }}">{{ $shown }}</td>
                            @elseif (in_array($column, $numbers, true) && $isFigure($value))
                                <td class="rp-num">{{ is_int($value) ? number_format($value) : $value }}</td>
                            @else
                                {{-- A kind written as a word ("asset", "expense") reads as one: "Asset". --}}
                                <td>{{ is_bool($value) ? ($value ? __('Yes') : __('No')) : (is_string($value) && preg_match('/^[a-z]+(_[a-z]+)*$/', $value) ? __(\Illuminate\Support\Str::headline($value)) : (is_scalar($value) ? $value : '')) }}</td>
                            @endif
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ max(1, count($section->columns)) }}" style="text-align: center; color: #64748b;">{{ __('Nothing in these dates') }}</td></tr>
                @endforelse
            </tbody>
            @if ($section->totals)
                <tfoot>
                    <tr>
                        @foreach (array_keys($section->columns) as $column)
                            @php
                                $value = $section->totals[$column] ?? null;
                                $shown = $money($value);
                            @endphp
                            @php $figure = $shown !== null || (in_array($column, $numbers, true) && $isFigure($value)); @endphp
                            <td class="{{ $figure ? 'rp-num' : '' }} {{ $shown !== null && str_starts_with($shown, '-') ? 'rp-neg' : '' }}">{{ $shown ?? (is_int($value) ? number_format($value) : (is_scalar($value) ? $value : '')) }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    @endforeach

    @if ($rest->isNotEmpty())
        <table class="rp-summary">
            @foreach ($rest as $key => $value)
                <tr>
                    <td>{{ __(\Illuminate\Support\Str::headline($key)) }}</td>
                    <td>
                        @if (is_bool($value))
                            <span style="color: {{ $value ? '#15803d' : '#b91c1c' }};">{{ $value ? '✓ '.__('Yes') : '✗ '.__('No') }}</span>
                        @else
                            {{ $money($value) ?? $day($value) ?? $value }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
@endsection
