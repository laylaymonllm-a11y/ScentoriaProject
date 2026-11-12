{{--
    Printable sales report.
 
    Rendered by SalesReportController::exportPdf(). dompdf only supports a
    narrow slice of CSS, so this deliberately uses tables for layout, inline
    hex colours (no CSS variables), and no flexbox or grid. It doubles as the
    browser-print fallback when dompdf isn't installed, which is the other
    reason it has to be plain, self-contained HTML.
 
    Palette matches the app theme: olive #4A6838, ink #222B1D,
    muted #7A8573, borders #D5CDBD, canvas #F5F1E6.
--}}
@php
    $range = $report['range'];
    $summary = $report['summary']['current'];
    $change = $report['summary']['change'];
 
    // $mmk carries the unit inline and is for the summary cards, where each
    // figure stands alone. $num is bare digits for table cells: the unit goes
    // in the column header instead, so a right-aligned money column is nothing
    // but numerals and reads as one clean stack. Matches the CSV export, which
    // already heads its money columns "(MMK)".
    $mmk = fn ($n) => 'MMK '.number_format((int) $n);
    $num = fn ($n) => number_format((int) $n);
    $pct = fn ($v) => $v === null ? '—' : ($v > 0 ? '+' : '').$v.'%';
 
    // Trend labels depend on how the batch data was bucketed. Week buckets are
    // keyed by their Monday as YYYYMMDD, and year buckets by a bare YYYY — both
    // fall through $humanDate safely, which returns its input untouched when the
    // key isn't 8 characters.
    $granularity = $range['granularity'];
    $trendNoun = ['day' => 'daily', 'week' => 'weekly', 'month' => 'monthly', 'year' => 'yearly'][$granularity] ?? 'daily';
    $trendColumn = ['day' => 'Date', 'week' => 'Week Starting', 'month' => 'Month', 'year' => 'Year'][$granularity] ?? 'Date';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Scentoria — Sales Report {{ $range['from'] }} to {{ $range['to'] }}</title>
    <style>
        @page { margin: 28px 32px; }
 
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9.5px;
            color: #222B1D;
            margin: 0;
        }
 
        h1 { font-size: 20px; margin: 0 0 2px; font-weight: normal; letter-spacing: 0.4px; }
        h2 {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.4px;
            color: #4A6838;
            margin: 20px 0 7px;
            border-bottom: 1px solid #D5CDBD;
            padding-bottom: 4px;
        }
 
        .meta { color: #7A8573; font-size: 9px; }
        .rule { border-bottom: 2px solid #4A6838; margin: 10px 0 0; }
 
        table { width: 100%; border-collapse: collapse; }
 
        .cards td {
            width: 25%;
            border: 1px solid #D5CDBD;
            background: #FAF8F2;
            padding: 9px 11px;
            vertical-align: top;
        }
        .cards .label {
            font-size: 7.5px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #7A8573;
            padding-bottom: 4px;
        }
        .cards .value { font-size: 13px; color: #222B1D; }
        .cards .delta { font-size: 7.5px; color: #7A8573; padding-top: 3px; }
 
        .data th {
            text-align: left;
            font-size: 7.5px;
            text-transform: uppercase;
            letter-spacing: 0.9px;
            color: #7A8573;
            border-bottom: 1px solid #D5CDBD;
            padding: 5px 6px;
        }
        .data td {
            padding: 5px 6px;
            border-bottom: 1px solid #EDE8DC;
        }
        .data tr.alt td { background: #FAF8F2; }
 
        /* Both selectors are needed, and both must out-specify `.data th`
           (0,1,1) above — a bare `.num { text-align: right }` loses to it, which
           left every numeric HEADER aligned left while its cells sat right. */
        .data th.num,
        .data td.num { text-align: right; }
 
        .muted { color: #7A8573; }
        .strong { color: #4A6838; }
 
        .bar-track { background: #E7E3D6; height: 5px; width: 100%; }
        .bar-fill { background: #4A6838; height: 5px; }
 
        .pill {
            font-size: 7.5px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
 
        .footer { margin-top: 18px; border-top: 1px solid #D5CDBD; padding-top: 6px; color: #7A8573; font-size: 7.5px; }
        .empty { color: #7A8573; padding: 10px 6px; font-style: italic; }
    </style>
</head>
<body>
 
    <h1>Scentoria — Sales Report</h1>
    <div class="meta">
        {{ $humanDate(str_replace('-', '', $range['from'])) }}
        &ndash;
        {{ $humanDate(str_replace('-', '', $range['to'])) }}
        &nbsp;&middot;&nbsp; {{ $range['days'] }} {{ \Illuminate\Support\Str::plural('day', $range['days']) }}
        &nbsp;&middot;&nbsp; compared with {{ $range['previous_from'] }} to {{ $range['previous_to'] }}
    </div>
    <div class="rule"></div>
 
    {{-- ---------------------------------------------------------------- --}}
    <h2>Summary</h2>
    <table class="cards">
        <tr>
            @foreach ([
                ['Total Revenue', $mmk($summary['total_revenue']), $change['total_revenue']],
                ['Total Orders', $num($summary['total_orders']), $change['total_orders']],
                ['Avg. Order Value', $mmk($summary['average_order_value']), $change['average_order_value']],
                ['Units Dispatched', $num($summary['total_units']), $change['total_units']],
            ] as [$label, $value, $delta])
                <td>
                    <div class="label">{{ $label }}</div>
                    <div class="value">{{ $value }}</div>
                    <div class="delta">{{ $pct($delta) }} vs previous period</div>
                </td>
            @endforeach
        </tr>
    </table>
 
    {{-- ---------------------------------------------------------------- --}}
    <h2>Revenue Trend ({{ $trendNoun }})</h2>
    @if (empty($report['revenue_series']))
        <p class="empty">No revenue recorded in this period.</p>
    @else
        @php $peak = max(1, collect($report['revenue_series'])->max('revenue')); @endphp
        <table class="data">
            <thead>
                <tr>
                    <th>{{ $trendColumn }}</th>
                    <th class="num">Revenue (MMK)</th>
                    <th class="num">Orders</th>
                    <th class="num">Units</th>
                    <th style="width: 34%;">Relative</th>
                </tr>
            </thead>
            <tbody>
                {{-- A bar per row stands in for the on-screen line chart:
                     dompdf can't render an SVG chart, but the shape of the
                     period still needs to be readable at a glance. --}}
                @foreach ($report['revenue_series'] as $i => $point)
                    <tr class="{{ $i % 2 ? 'alt' : '' }}">
                        <td>
                            @if ($granularity === 'month')
                                {{-- The leading "!" resets every unspecified field. Without it
                                     PHP fills the day from today, so parsing "202602" on the
                                     31st overflows into March and prints the wrong month. --}}
                                {{ \Illuminate\Support\Carbon::createFromFormat('!Ym', $point['label'])->format('M Y') }}
                            @else
                                {{ $humanDate($point['label']) }}
                            @endif
                        </td>
                        <td class="num strong">{{ $num($point['revenue']) }}</td>
                        <td class="num muted">{{ $point['orders'] }}</td>
                        <td class="num muted">{{ $point['units'] }}</td>
                        <td>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: {{ round(($point['revenue'] / $peak) * 100, 1) }}%;"></div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
 
    {{-- ---------------------------------------------------------------- --}}
    <h2>Sales by Scent Profile</h2>
    @if (empty($report['scent_breakdown']))
        <p class="empty">No scent-profile sales in this period.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>Scent Profile</th>
                    <th class="num">Revenue (MMK)</th>
                    <th class="num">Units</th>
                    <th class="num">Share</th>
                    <th style="width: 30%;">&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['scent_breakdown'] as $i => $scent)
                    <tr class="{{ $i % 2 ? 'alt' : '' }}">
                        <td>{{ $scent['scent_name'] }}</td>
                        <td class="num strong">{{ $num($scent['revenue']) }}</td>
                        <td class="num muted">{{ $num($scent['units']) }}</td>
                        <td class="num">{{ $scent['share'] }}%</td>
                        <td>
                            <div class="bar-track">
                                <div class="bar-fill" style="width: {{ min(100, $scent['share']) }}%;"></div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
 
    {{-- ---------------------------------------------------------------- --}}
    {{-- Headings mirror the on-screen section labels so a printed report and
         the page can be cross-referenced without translation. --}}
    <h2>Product Sales Performance</h2>
    @if (empty($report['top_products']))
        <p class="empty">No products sold in this period.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th style="width: 26px;">#</th>
                    <th>Product Name</th>
                    <th>Brand</th>
                    <th class="num">Units Sold</th>
                    <th class="num">Total Revenue (MMK)</th>
                    <th class="num">Share</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['top_products'] as $i => $product)
                    <tr class="{{ $i % 2 ? 'alt' : '' }}">
                        <td class="strong">{{ $product['rank'] }}</td>
                        <td>{{ $product['product_name'] }}</td>
                        <td class="muted">{{ $product['brand_name'] }}</td>
                        <td class="num">{{ $num($product['units_sold']) }}</td>
                        <td class="num strong">{{ $num($product['revenue']) }}</td>
                        <td class="num">{{ $product['share'] }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
 
    {{-- ---------------------------------------------------------------- --}}
    {{-- No status column: every row is a completed order, so the whole report
         is revenue-recognised and every figure on it agrees. --}}
    <h2>Detailed Transactions ({{ count($report['transactions']) }} completed)</h2>
    @if (empty($report['transactions']))
        <p class="empty">No transactions in this period.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Date</th>
                    <th class="num">Items</th>
                    <th class="num">Amount (MMK)</th>
                    <th>Payment</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['transactions'] as $i => $txn)
                    <tr class="{{ $i % 2 ? 'alt' : '' }}">
                        <td>#{{ str_pad($txn['order_id'], 4, '0', STR_PAD_LEFT) }}</td>
                        <td>{{ $txn['customer_name'] }}</td>
                        <td class="muted">{{ $humanDate($txn['date']) }}</td>
                        <td class="num muted">{{ $txn['item_count'] }}</td>
                        <td class="num strong">{{ $num($txn['total_amount']) }}</td>
                        <td class="muted">{{ $paymentLabel($txn['payment_method'] ?? null) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
 
    <div class="footer">
        Figures computed by the COBOL sales batch job
        (last run {{ $report['generated_at'] ?? 'never' }}).
        Completed orders only throughout — pending and cancelled orders are
        excluded from every figure in this report.
    </div>
 
</body>
</html>
 
 