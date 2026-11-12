<?php
 
namespace App\Http\Controllers;
 
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
 
class SalesReportController extends Controller
{
    /**
     * Rows returned in the transaction audit log. Generous enough that a
     * normal month fits in one payload, capped so a "last 2 years" range
     * can't try to ship every order the store has ever taken to the browser.
     */
    private const TRANSACTION_LIMIT = 500;
 
    /**
     * Products listed in the product-performance table.
     *
     * Raised from 15 once that table became a filterable deep dive rather
     * than a top-sellers widget — filtering and sorting are pointless if the
     * server has already discarded everything below rank 15. Share is computed
     * against the full range total regardless, so the percentages stay honest
     * even if the tail is cut here.
     */
    private const TOP_PRODUCT_LIMIT = 100;
 
    /**
     * A day range longer than this switches the trend chart to month
     * buckets — 90 daily points is already dense, and beyond that the
     * x-axis is unreadable.
     */
    private const AUTO_MONTH_THRESHOLD = 90;
 
    // =================================================================
    // Legacy period endpoint (admin dashboard)
    // =================================================================
 
    /**
     * GET /api/admin/sales-report?period=day|month|all
     *
     * Kept exactly as it was: the admin dashboard's revenue chart and
     * all-time "Top Products" widget read this shape. The Sales Report page
     * uses range() below instead.
     *
     * Reads only — all the aggregation already happened in the COBOL batch
     * job (nightly, or on demand via runBatch()).
     */
    public function index(Request $request)
    {
        $period = $request->query('period', 'month');
 
        return response()->json(match ($period) {
            'day' => $this->dayReport(),
            'all' => $this->allTimeReport(),
            default => $this->monthReport(),
        });
    }
 
    /**
     * POST /api/admin/sales-report/run-batch
     *
     * Runs the same pipeline the nightly schedule runs, on demand.
     * Synchronous — the COBOL job is fast enough at this data scale that
     * the admin can just wait for the response.
     *
     * Responds in whichever shape the caller asked for, so both the
     * dashboard (period=) and the report page (from=/to=) can use it.
     */
    public function runBatch(Request $request)
    {
        try {
            $exitCode = Artisan::call('sales:run-batch');
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Batch job failed: '.$e->getMessage()], 500);
        }
 
        if ($exitCode !== 0) {
            return response()->json([
                'message' => 'Batch job failed. Check server logs for details.',
                'output' => Artisan::output(),
            ], 500);
        }
 
        return $request->has('from') || $request->has('to')
            ? $this->range($request)
            : $this->index($request);
    }
 
    // =================================================================
    // Date-range endpoint (Sales Report page)
    // =================================================================
 
    /**
     * GET /api/admin/sales-report/range?from=&to=&granularity=
     *
     * Everything in the response is scoped to [from, to] — summary cards,
     * trend chart, scent breakdown, top products and the transaction log.
     * That's only possible because the COBOL job writes day-grain rows for
     * each dimension; this method just re-sums the slice that was asked for.
     */
    public function range(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);
        $granularity = $this->resolveGranularity($request, $from, $to);
 
        return response()->json($this->buildReport($from, $to, $granularity));
    }
 
    /**
     * GET /api/admin/sales-report/export?format=csv|pdf&from=&to=&granularity=
     *
     * Exports are generated from buildReport() — the same method that feeds
     * the screen — so a downloaded file can never disagree with what the
     * admin was looking at when they clicked the button.
     */
    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));
 
        [$from, $to] = $this->resolveRange($request);
        $granularity = $this->resolveGranularity($request, $from, $to);
        $report = $this->buildReport($from, $to, $granularity);
 
        $filename = 'scentoria-sales-report_'.$from->format('Y-m-d').'_'.$to->format('Y-m-d');
 
        return match ($format) {
            'pdf' => $this->exportPdf($report, $filename),
            default => $this->exportCsv($report, $filename),
        };
    }
 
    // =================================================================
    // Report assembly
    // =================================================================
 
    private function buildReport(Carbon $from, Carbon $to, string $granularity): array
    {
        $current = $this->summarise($from, $to);
 
        // Previous window = the same number of days, ending the day before
        // this one starts. Comparing "last 7 days" against "the 7 days
        // before that" is the only comparison that isn't misleading.
        // Cast before arithmetic: Carbon 3's diffInDays() returns a float.
        $lengthInDays = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($lengthInDays - 1);
        $previous = $this->summarise($prevFrom, $prevTo);
 
        return [
            'range' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'days' => $lengthInDays,
                'granularity' => $granularity,
                'previous_from' => $prevFrom->format('Y-m-d'),
                'previous_to' => $prevTo->format('Y-m-d'),
            ],
            'generated_at' => DB::table('daily_sales_reports')->max('generated_at'),
            'summary' => [
                'current' => $current,
                'previous' => $previous,
                'change' => [
                    'total_revenue' => $this->percentChange($previous['total_revenue'], $current['total_revenue']),
                    'total_orders' => $this->percentChange($previous['total_orders'], $current['total_orders']),
                    'average_order_value' => $this->percentChange($previous['average_order_value'], $current['average_order_value']),
                    'total_units' => $this->percentChange($previous['total_units'], $current['total_units']),
                ],
            ],
            'revenue_series' => match ($granularity) {
                'week' => $this->weekSeries($from, $to),
                'month' => $this->monthSeries($from, $to),
                'year' => $this->yearSeries($from, $to),
                default => $this->daySeries($from, $to),
            },
            'scent_breakdown' => $this->scentBreakdown($from, $to),
            'top_products' => $this->topProducts($from, $to),
            'transactions' => $this->transactions($from, $to),
        ];
    }
 
    /**
     * Revenue / orders / units for a window, straight off the day-grain
     * table the COBOL job produced. AOV is derived here rather than stored
     * because averaging stored averages across days would be wrong.
     */
    private function summarise(Carbon $from, Carbon $to): array
    {
        $row = DB::table('daily_sales_reports')
            ->whereBetween('date', [$from->format('Ymd'), $to->format('Ymd')])
            ->selectRaw('COALESCE(SUM(total_revenue), 0) as revenue')
            ->selectRaw('COALESCE(SUM(order_count), 0) as orders')
            ->selectRaw('COALESCE(SUM(units_sold), 0) as units')
            ->first();
 
        $revenue = (int) ($row->revenue ?? 0);
        $orders = (int) ($row->orders ?? 0);
 
        return [
            'total_revenue' => $revenue,
            'total_orders' => $orders,
            'total_units' => (int) ($row->units ?? 0),
            'average_order_value' => $orders > 0 ? (int) round($revenue / $orders) : 0,
        ];
    }
 
    /**
     * Every day in the range gets a point, even ones with no sales —
     * otherwise the chart only plots the handful of days that happen to
     * have orders and those few points stretch to fill the whole width.
     */
    private function daySeries(Carbon $from, Carbon $to): array
    {
        $existing = DB::table('daily_sales_reports')
            ->whereBetween('date', [$from->format('Ymd'), $to->format('Ymd')])
            ->get(['date', 'total_revenue', 'order_count', 'units_sold'])
            ->keyBy('date');
 
        $series = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $key = $cursor->format('Ymd');
            $row = $existing->get($key);
 
            $series[] = [
                'label' => $key,
                'revenue' => (int) ($row->total_revenue ?? 0),
                'orders' => (int) ($row->order_count ?? 0),
                'units' => (int) ($row->units_sold ?? 0),
            ];
        }
 
        return $series;
    }
 
    /**
     * Week buckets, Monday-anchored.
     *
     * Bucketed in PHP rather than SQL: the date column is a char(8) string, so
     * grouping by week would mean WEEK(STR_TO_DATE(...)) with MySQL's mode
     * argument deciding where weeks start — easy to get subtly wrong, and
     * invisible when it is. Carbon's startOfWeek is explicit and portable.
     *
     * The label is the bucket's Monday as YYYYMMDD, so the frontend can format
     * it with the same day formatter it already has.
     */
    private function weekSeries(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('daily_sales_reports')
            ->whereBetween('date', [$from->format('Ymd'), $to->format('Ymd')])
            ->get(['date', 'total_revenue', 'order_count', 'units_sold']);
 
        $buckets = [];
 
        // Pre-seed every week in the range so quiet weeks still plot as zero
        // instead of being skipped and stretching the neighbouring points.
        $cursor = $from->copy()->startOfWeek();
        $end = $to->copy()->startOfWeek();
        while ($cursor->lte($end)) {
            $buckets[$cursor->format('Ymd')] = ['revenue' => 0, 'orders' => 0, 'units' => 0];
            $cursor->addWeek();
        }
 
        foreach ($rows as $row) {
            $key = Carbon::createFromFormat('!Ymd', $row->date)->startOfWeek()->format('Ymd');
 
            // A week whose Monday falls before `from` still belongs in the
            // first bucket, so clamp rather than drop.
            if (! isset($buckets[$key])) {
                $key = $from->copy()->startOfWeek()->format('Ymd');
            }
 
            $buckets[$key]['revenue'] += (int) $row->total_revenue;
            $buckets[$key]['orders'] += (int) $row->order_count;
            $buckets[$key]['units'] += (int) $row->units_sold;
        }
 
        $series = [];
        foreach ($buckets as $label => $totals) {
            $series[] = ['label' => $label] + $totals;
        }
 
        return $series;
    }
 
    /**
     * Year buckets, for the All Time view. Zero-filling is pointless here —
     * a year with no orders at all means the store wasn't trading, and an
     * empty point would just pad the chart.
     */
    private function yearSeries(Carbon $from, Carbon $to): array
    {
        return DB::table('daily_sales_reports')
            ->whereBetween('date', [$from->format('Ymd'), $to->format('Ymd')])
            ->selectRaw('LEFT(`date`, 4) as label')
            ->selectRaw('SUM(total_revenue) as revenue')
            ->selectRaw('SUM(order_count) as orders')
            ->selectRaw('SUM(units_sold) as units')
            ->groupByRaw('LEFT(`date`, 4)')
            ->orderByRaw('LEFT(`date`, 4) asc')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label,
                'revenue' => (int) $row->revenue,
                'orders' => (int) $row->orders,
                'units' => (int) $row->units,
            ])->all();
    }
 
    /**
     * Month buckets are summed from the DAILY table, not read from
     * monthly_sales_reports, because a range can start or end mid-month —
     * the stored monthly total would leak revenue from outside the range.
     */
    private function monthSeries(Carbon $from, Carbon $to): array
    {
        $existing = DB::table('daily_sales_reports')
            ->whereBetween('date', [$from->format('Ymd'), $to->format('Ymd')])
            ->selectRaw('LEFT(`date`, 6) as ym')
            ->selectRaw('SUM(total_revenue) as revenue')
            ->selectRaw('SUM(order_count) as orders')
            ->selectRaw('SUM(units_sold) as units')
            ->groupByRaw('LEFT(`date`, 6)')
            ->get()
            ->keyBy('ym');
 
        $series = [];
        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->startOfMonth();
 
        while ($cursor->lte($end)) {
            $key = $cursor->format('Ym');
            $row = $existing->get($key);
 
            $series[] = [
                'label' => $key,
                'revenue' => (int) ($row->revenue ?? 0),
                'orders' => (int) ($row->orders ?? 0),
                'units' => (int) ($row->units ?? 0),
            ];
 
            $cursor->addMonth();
        }
 
        return $series;
    }
 
    /**
     * Sales by scent profile (Floral, Woody, Citrus, Oriental, ...).
     * Shares are computed against the scent total rather than the summary
     * revenue so the percentages always add up to 100 in the chart legend.
     */
    private function scentBreakdown(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('scent_daily_sales_reports')
            ->whereBetween('date', [$from->format('Ymd'), $to->format('Ymd')])
            ->select('scent_name')
            ->selectRaw('SUM(revenue) as revenue')
            ->selectRaw('SUM(quantity_sold) as units')
            ->groupBy('scent_name')
            ->orderByDesc('revenue')
            ->get();
 
        $total = (int) $rows->sum('revenue');
 
        return $rows->map(fn ($row) => [
            'scent_name' => $row->scent_name,
            'revenue' => (int) $row->revenue,
            'units' => (int) $row->units,
            'share' => $total > 0 ? round(((int) $row->revenue / $total) * 100, 1) : 0.0,
        ])->values()->all();
    }
 
    /**
     * Top sellers for the range, re-ranked from day-grain rows. Share is
     * each product's slice of the range's product revenue, which is what
     * "Percentage Share (%)" means on a sales report — its contribution to
     * the sales mix.
     */
    private function topProducts(Carbon $from, Carbon $to): array
    {
        $window = [$from->format('Ymd'), $to->format('Ymd')];
 
        $total = (int) DB::table('product_daily_sales_reports')
            ->whereBetween('date', $window)
            ->sum('revenue');
 
        $rows = DB::table('product_daily_sales_reports')
            ->whereBetween('date', $window)
            ->select('product_name')
            ->selectRaw('MAX(brand_name) as brand_name')
            ->selectRaw('SUM(quantity_sold) as units_sold')
            ->selectRaw('SUM(revenue) as revenue')
            ->groupBy('product_name')
            ->orderByDesc('units_sold')
            ->orderByDesc('revenue')
            ->limit(self::TOP_PRODUCT_LIMIT)
            ->get();
 
        return $rows->values()->map(fn ($row, $i) => [
            'rank' => $i + 1,
            'product_name' => $row->product_name,
            'brand_name' => $row->brand_name ?: '—',
            'units_sold' => (int) $row->units_sold,
            'revenue' => (int) $row->revenue,
            'share' => $total > 0 ? round(((int) $row->revenue / $total) * 100, 1) : 0.0,
        ])->all();
    }
 
    /**
     * Completed transactions for the range.
     *
     * COMPLETED ONLY, deliberately. This is a sales report: a cancelled order
     * is not a sale, and including one would put a row on the page that
     * contributed nothing to any other figure on it.
     *
     * The decisive reason is internal consistency. Revenue, order counts, units,
     * top products and the scent breakdown are all revenue-recognised — the
     * COBOL job only accumulates completed orders into those (see
     * REVENUE RECOGNITION in cobol/SALESBATCH.cob). A transaction table listing
     * every status would disagree with every other number on the same page for
     * the same date range, and its total would be a sum of amounts that were
     * never collected.
     *
     * order_sales_reports still stores every status — the batch writes them all,
     * and the Orders page is where status-agnostic browsing belongs. Only this
     * report narrows the view.
     */
    private function transactions(Carbon $from, Carbon $to): array
    {
        return DB::table('order_sales_reports')
            ->whereBetween('date', [$from->format('Ymd'), $to->format('Ymd')])
            ->where('status', 'completed')
            ->orderByDesc('date')
            ->orderByDesc('order_id')
            ->limit(self::TRANSACTION_LIMIT)
            ->get(['order_id', 'date', 'customer_name', 'total_amount', 'item_count', 'payment_method'])
            ->map(fn ($row) => [
                'order_id' => (int) $row->order_id,
                'date' => $row->date,
                'customer_name' => $row->customer_name ?: 'Unknown Customer',
                'total_amount' => (int) $row->total_amount,
                'item_count' => (int) $row->item_count,
                'payment_method' => $row->payment_method,
            ])->all();
    }
 
    // =================================================================
    // Request parsing
    // =================================================================
 
    /**
     * Resolve from/to into a normalised, ordered pair of dates.
     *
     * Defaults to the current calendar month. Swaps the pair if the admin
     * picked them backwards, and clamps `to` to today so the chart doesn't
     * trail off into a flat line of future zeroes.
     */
    private function resolveRange(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'granularity' => ['nullable', 'in:day,week,month,year'],
        ]);
 
        $today = Carbon::today();
 
        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : $today->copy()->startOfMonth();
 
        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->startOfDay()
            : $today->copy();
 
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
 
        if ($to->gt($today)) {
            $to = $today->copy();
        }
 
        return [$from, $to];
    }
 
    private function resolveGranularity(Request $request, Carbon $from, Carbon $to): string
    {
        if (in_array($request->query('granularity'), ['day', 'week', 'month', 'year'], true)) {
            return $request->query('granularity');
        }
 
        // Only reached when the caller omits granularity entirely — the report
        // page always sends one, since its period dropdown picks the window and
        // the bucketing together.
        return (int) $from->diffInDays($to) + 1 > self::AUTO_MONTH_THRESHOLD ? 'month' : 'day';
    }
 
    /**
     * Growth against the previous window. Returns null rather than 0 when
     * there's no baseline — "no prior data" and "flat" are different
     * stories and the UI renders them differently.
     */
    private function percentChange(int|float $previous, int|float $current): ?float
    {
        if ($previous <= 0) {
            return null;
        }
 
        return round((($current - $previous) / $previous) * 100, 1);
    }
 
    // =================================================================
    // Exports
    // =================================================================
 
    /**
     * Multi-section CSV: summary, then trend, then each table. Opens
     * directly in Excel, Numbers and Sheets, which is what "Excel export"
     * means in practice for an accounting hand-off.
     */
    private function exportCsv(array $report, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
 
            // UTF-8 BOM so Excel on Windows renders non-ASCII customer and
            // product names correctly instead of mojibake.
            fwrite($out, "\xEF\xBB\xBF");
 
            $range = $report['range'];
            $summary = $report['summary']['current'];
 
            fputcsv($out, ['Scentoria — Sales Report']);
            fputcsv($out, ['Period', $range['from'].' to '.$range['to']]);
            fputcsv($out, ['Generated', $report['generated_at'] ?? 'Not yet generated']);
            fputcsv($out, []);
 
            fputcsv($out, ['Summary']);
            fputcsv($out, ['Metric', 'Value', 'Change vs previous period']);
            foreach ([
                // Labels match the on-screen sections and the PDF, so the three
                // outputs can be cross-referenced without translation.
                'Total Revenue (MMK)' => 'total_revenue',
                'Total Orders' => 'total_orders',
                'Avg. Order Value (MMK)' => 'average_order_value',
                'Units Dispatched' => 'total_units',
            ] as $label => $key) {
                $change = $report['summary']['change'][$key];
                fputcsv($out, [$label, $summary[$key], $change === null ? 'n/a' : $change.'%']);
            }
            fputcsv($out, []);
 
            $periodLabel = match ($range['granularity']) {
                'week' => 'Week Starting',
                'month' => 'Month',
                'year' => 'Year',
                default => 'Date',
            };
            fputcsv($out, ['Revenue Trend']);
            fputcsv($out, [$periodLabel, 'Revenue (MMK)', 'Orders', 'Units']);
            foreach ($report['revenue_series'] as $point) {
                fputcsv($out, [$point['label'], $point['revenue'], $point['orders'], $point['units']]);
            }
            fputcsv($out, []);
 
            fputcsv($out, ['Sales by Scent Profile']);
            fputcsv($out, ['Scent Profile', 'Revenue (MMK)', 'Units', 'Share (%)']);
            foreach ($report['scent_breakdown'] as $scent) {
                fputcsv($out, [$scent['scent_name'], $scent['revenue'], $scent['units'], $scent['share']]);
            }
            fputcsv($out, []);
 
            fputcsv($out, ['Product Sales Performance']);
            fputcsv($out, ['Rank', 'Product Name', 'Brand', 'Units Sold', 'Total Revenue (MMK)', 'Share (%)']);
            foreach ($report['top_products'] as $product) {
                fputcsv($out, [
                    $product['rank'],
                    $product['product_name'],
                    $product['brand_name'],
                    $product['units_sold'],
                    $product['revenue'],
                    $product['share'],
                ]);
            }
            fputcsv($out, []);
 
            // No status column — every row here is completed by definition.
            fputcsv($out, ['Detailed Transactions (completed orders)']);
            fputcsv($out, ['Order ID', 'Customer Name', 'Date', 'Items', 'Amount (MMK)', 'Payment Method']);
            foreach ($report['transactions'] as $txn) {
                fputcsv($out, [
                    $txn['order_id'],
                    $txn['customer_name'],
                    $this->humanDate($txn['date']),
                    $txn['item_count'],
                    $txn['total_amount'],
                    $this->paymentLabel($txn['payment_method']),
                ]);
            }
 
            fclose($out);
        }, $filename.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
 
    private function exportPdf(array $report, string $filename)
    {
        $html = view('reports.sales', [
            'report' => $report,
            'humanDate' => fn ($ymd) => $this->humanDate($ymd),
            'paymentLabel' => fn ($m) => $this->paymentLabel($m),
        ])->render();
 
        // dompdf is optional. Without it the export still works — it just
        // hands back the same printable HTML for the browser's own
        // "Save as PDF", rather than 500-ing on a missing package.
        if (! class_exists(\Dompdf\Dompdf::class)) {
            return response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Pdf-Fallback' => 'dompdf-not-installed',
            ]);
        }
 
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
 
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
        ]);
    }
 
    /**
     * 'virtual_currency' -> 'Wallet'. Keeps the raw enum out of exports that
     * go to management, and degrades to a title-cased version of whatever
     * it's given if a new payment method is added later.
     */
    private function paymentLabel(?string $method): string
    {
        return match ($method) {
            'cash' => 'Cash',
            'virtual_currency' => 'Wallet',
            null, '' => '—',
            default => ucwords(str_replace('_', ' ', $method)),
        };
    }
 
    /** "20260730" -> "30 Jul 2026". Falls back to the raw key if malformed. */
    private function humanDate(?string $ymd): string
    {
        if (! $ymd || strlen($ymd) !== 8) {
            return (string) $ymd;
        }
 
        // Leading "!" resets unspecified fields instead of inheriting the
        // current time, so the result can't drift with the clock.
        return Carbon::createFromFormat('!Ymd', $ymd)->format('d M Y');
    }
 
    // =================================================================
    // Legacy period reports (unchanged behaviour)
    // =================================================================
 
    private function dayReport(): array
    {
        // Every day is present in the series, even ones with zero revenue —
        // otherwise the chart only shows the handful of days that happen to
        // have orders, and those few points stretch to fill the whole width.
        $existing = DB::table('daily_sales_reports')->pluck('total_revenue', 'date');
 
        $series = collect(range(29, 0))->map(function ($daysAgo) use ($existing) {
            $date = Carbon::now()->subDays($daysAgo)->format('Ymd');
 
            return [
                'label' => $date,
                'total_revenue' => (int) ($existing[$date] ?? 0),
            ];
        })->values();
 
        $today = Carbon::now()->format('Ymd');
        $yesterday = Carbon::now()->subDay()->format('Ymd');
        $currentRevenue = (int) ($existing[$today] ?? 0);
        $previousRevenue = (int) ($existing[$yesterday] ?? 0);
 
        return [
            'period' => 'day',
            'current_period_revenue' => $currentRevenue,
            'growth_percent' => $this->percentChange($previousRevenue, $currentRevenue),
            'revenue_series' => $series,
            'top_products' => $this->allTimeTopProducts(),
            'generated_at' => DB::table('daily_sales_reports')->max('generated_at'),
        ];
    }
 
    private function monthReport(): array
    {
        // Same reasoning as dayReport() — always show the last 12 calendar
        // months, zero-filled, instead of only months that had any orders.
        $existing = DB::table('monthly_sales_reports')->pluck('total_revenue', 'year_month');
 
        $series = collect(range(11, 0))->map(function ($monthsAgo) use ($existing) {
            $ym = Carbon::now()->subMonths($monthsAgo)->format('Ym');
 
            return [
                'label' => $ym,
                'total_revenue' => (int) ($existing[$ym] ?? 0),
            ];
        })->values();
 
        $currentMonth = Carbon::now()->format('Ym');
        $previousMonth = Carbon::now()->subMonth()->format('Ym');
        $currentRevenue = (int) ($existing[$currentMonth] ?? 0);
        $previousRevenue = (int) ($existing[$previousMonth] ?? 0);
 
        return [
            'period' => 'month',
            'current_period_revenue' => $currentRevenue,
            'growth_percent' => $this->percentChange($previousRevenue, $currentRevenue),
            'revenue_series' => $series,
            'top_products' => $this->allTimeTopProducts(),
            'generated_at' => DB::table('monthly_sales_reports')->max('generated_at'),
        ];
    }
 
    private function allTimeReport(): array
    {
        $yearly = DB::table('monthly_sales_reports')
            ->selectRaw('LEFT(`year_month`, 4) as label, SUM(total_revenue) as total_revenue')
            ->groupByRaw('LEFT(`year_month`, 4)')
            ->orderByRaw('LEFT(`year_month`, 4) asc')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label,
                'total_revenue' => (int) $row->total_revenue,
            ]);
 
        $currentYear = Carbon::now()->format('Y');
        $previousYear = Carbon::now()->subYear()->format('Y');
        $currentRevenue = (int) ($yearly->firstWhere('label', $currentYear)['total_revenue'] ?? 0);
        $previousRevenue = (int) ($yearly->firstWhere('label', $previousYear)['total_revenue'] ?? 0);
 
        return [
            'period' => 'all',
            'current_period_revenue' => (int) $yearly->sum('total_revenue'),
            'growth_percent' => $this->percentChange($previousRevenue, $currentRevenue),
            'revenue_series' => $yearly->values(),
            'top_products' => $this->allTimeTopProducts(),
            'generated_at' => DB::table('monthly_sales_reports')->max('generated_at'),
        ];
    }
 
    /** All-time roll-up straight from the COBOL job, pre-ranked by units. */
    private function allTimeTopProducts()
    {
        return DB::table('product_sales_reports')
            ->orderBy('rank')
            ->limit(10)
            ->get(['product_name', 'quantity_sold', 'revenue', 'rank']);
    }
}
 
 