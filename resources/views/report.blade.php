{{-- A report on nit-master's A4 print page (company logo, name and address from Settings). --}}
@extends('nit::print')

@section('title', $report->title)

@section('subtitle')
    {{ __(':from to :to', ['from' => $report->filters['from'] ?? '', 'to' => $report->filters['to'] ?? '']) }}
@endsection

@section('content')
    @foreach ($report->sections as $section)
        <h3 style="margin: 18px 0 6px;">{{ $section->title }}</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
            <thead>
                <tr>
                    @foreach ($section->columns as $label)
                        <th style="text-align: left; border-bottom: 1px solid #999; padding: 3px 4px;">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($section->rows as $row)
                    <tr>
                        @foreach (array_keys($section->columns) as $key)
                            <td style="padding: 2px 4px; border-bottom: 1px solid #eee; {{ is_numeric($row[$key] ?? null) ? 'text-align: right;' : '' }}">{{ $row[$key] ?? '' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @if ($section->totals)
                <tfoot>
                    <tr>
                        @foreach (array_keys($section->columns) as $key)
                            <th style="padding: 3px 4px; border-top: 1px solid #999; {{ is_numeric($section->totals[$key] ?? null) ? 'text-align: right;' : 'text-align: left;' }}">{{ $section->totals[$key] ?? '' }}</th>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    @endforeach

    @if ($report->summary)
        <table style="margin-top: 16px; font-size: 11px;">
            @foreach ($report->summary as $key => $value)
                @continue(is_array($value))
                <tr>
                    <td style="padding: 2px 12px 2px 0;">{{ \Illuminate\Support\Str::headline($key) }}</td>
                    <td style="text-align: right;">{{ is_bool($value) ? ($value ? __('Yes') : __('No')) : $value }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@endsection
