<?php
 
namespace App\Console\Commands;
 
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
 
class RunSalesBatchCommand extends Command
{
    protected $signature = 'sales:run-batch';
    protected $description = 'Export order data, run the COBOL sales batch job, and ingest the results into the report tables';
 
    /**
     * Fixed-width offsets for every COBOL output file.
     *
     * Each entry is [start, length] and mirrors the OUT-* PIC clauses in
     * cobol/SALESBATCH.cob. This is the single place the layouts are
     * written down on the PHP side — if a PIC clause changes over there,
     * this array is the only thing that has to change here.
     */
    private const LAYOUTS = [
        // daily_summary.dat — 8 + 12 + 7 + 9 = 36
        'daily' => [
            'date' => [0, 8],
            'revenue' => [8, 12],
            'orders' => [20, 7],
            'units' => [27, 9],
        ],
        // monthly_summary.dat — 6 + 12 + 7 + 9 = 34
        'monthly' => [
            'year_month' => [0, 6],
            'revenue' => [6, 12],
            'orders' => [18, 7],
            'units' => [25, 9],
        ],
        // product_sales.dat — 30 + 7 + 12 = 49 (all-time, admin dashboard)
        'product' => [
            'name' => [0, 30],
            'quantity' => [30, 7],
            'revenue' => [37, 12],
        ],
        // product_daily.dat — 8 + 30 + 20 + 7 + 12 = 77
        'product_daily' => [
            'date' => [0, 8],
            'name' => [8, 30],
            'brand' => [38, 20],
            'quantity' => [58, 7],
            'revenue' => [65, 12],
        ],
        // scent_daily.dat — 8 + 20 + 7 + 12 = 47
        'scent_daily' => [
            'date' => [0, 8],
            'scent' => [8, 20],
            'quantity' => [28, 7],
            'revenue' => [35, 12],
        ],
        // transactions.dat — 8 + 8 + 30 + 12 + 5 + 10 + 20 = 93
        'transactions' => [
            'date' => [0, 8],
            'order_id' => [8, 8],
            'customer' => [16, 30],
            'amount' => [46, 12],
            'items' => [58, 5],
            'status' => [63, 10],
            'payment' => [73, 20],
        ],
    ];
 
    public function handle(): int
    {
        $this->call('sales:export');
 
        $binary = base_path(env('COBOL_BATCH_BIN', 'cobol/bin/sales_batch'));
        $inputDir = storage_path('app/cobol/input');
        $outputDir = storage_path('app/cobol/output');
 
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
 
        if (! is_file($binary)) {
            $this->error("COBOL batch binary not found at {$binary}. Compile it first — see cobol/README.md.");
 
            return self::FAILURE;
        }
 
        $process = new Process([$binary], null, [
            'SALES_INPUT_DIR' => $inputDir,
            'SALES_OUTPUT_DIR' => $outputDir,
        ]);
        $process->run();
 
        if (! $process->isSuccessful()) {
            $this->error('COBOL batch job failed: '.$process->getErrorOutput());
 
            return self::FAILURE;
        }
 
        // SALESBATCH.cob exits 0 even when an internal OCCURS table fills
        // up — a partial report beats no report — but it writes a warning
        // to stderr. Surface it instead of swallowing it.
        if (trim($process->getErrorOutput()) !== '') {
            $this->warn(trim($process->getErrorOutput()));
        }
 
        $this->info('COBOL batch job completed. Ingesting results...');
 
        $generatedAt = Carbon::now();
 
        // Full rebuild rather than an incremental merge. The COBOL job
        // reprocesses all history on every run, so its output is the
        // complete truth — and a rebuild is the only way a day whose
        // orders were all cancelled since the last run actually loses its
        // revenue instead of keeping a stale row.
        DB::transaction(function () use ($outputDir, $generatedAt) {
            $this->ingestDaily("{$outputDir}/daily_summary.dat", $generatedAt);
            $this->ingestMonthly("{$outputDir}/monthly_summary.dat", $generatedAt);
            $this->ingestProductAllTime("{$outputDir}/product_sales.dat", $generatedAt);
            $this->ingestProductDaily("{$outputDir}/product_daily.dat", $generatedAt);
            $this->ingestScentDaily("{$outputDir}/scent_daily.dat", $generatedAt);
            $this->ingestTransactions("{$outputDir}/transactions.dat", $generatedAt);
        });
 
        $this->info('Sales report tables updated.');
 
        return self::SUCCESS;
    }
 
    // -----------------------------------------------------------------
    // Ingest
    // -----------------------------------------------------------------
 
    private function ingestDaily(string $path, Carbon $generatedAt): void
    {
        $rows = $this->parse($path, 'daily', fn (array $f) => [
            'date' => $f['date'],
            'total_revenue' => (int) $f['revenue'],
            'order_count' => (int) $f['orders'],
            'units_sold' => (int) $f['units'],
        ], $generatedAt);
 
        $this->replace('daily_sales_reports', $rows);
    }
 
    private function ingestMonthly(string $path, Carbon $generatedAt): void
    {
        $rows = $this->parse($path, 'monthly', fn (array $f) => [
            'year_month' => $f['year_month'],
            'total_revenue' => (int) $f['revenue'],
            'order_count' => (int) $f['orders'],
            'units_sold' => (int) $f['units'],
        ], $generatedAt);
 
        $this->replace('monthly_sales_reports', $rows);
    }
 
    /**
     * All-time product roll-up. The COBOL job already sorted this file by
     * units descending, so file position IS the rank — that's why this one
     * reads with an incrementing counter instead of sorting in PHP.
     */
    private function ingestProductAllTime(string $path, Carbon $generatedAt): void
    {
        $rank = 1;
 
        $rows = $this->parse($path, 'product', function (array $f) use (&$rank) {
            return [
                'product_name' => $f['name'],
                'quantity_sold' => (int) $f['quantity'],
                'revenue' => (int) $f['revenue'],
                'rank' => $rank++,
            ];
        }, $generatedAt);
 
        $this->replace('product_sales_reports', $rows);
    }
 
    private function ingestProductDaily(string $path, Carbon $generatedAt): void
    {
        $rows = $this->parse($path, 'product_daily', fn (array $f) => [
            'date' => $f['date'],
            'product_name' => $f['name'],
            'brand_name' => $f['brand'] !== '' ? $f['brand'] : null,
            'quantity_sold' => (int) $f['quantity'],
            'revenue' => (int) $f['revenue'],
        ], $generatedAt);
 
        $this->replace('product_daily_sales_reports', $rows);
    }
 
    private function ingestScentDaily(string $path, Carbon $generatedAt): void
    {
        $rows = $this->parse($path, 'scent_daily', fn (array $f) => [
            'date' => $f['date'],
            'scent_name' => $f['scent'],
            'quantity_sold' => (int) $f['quantity'],
            'revenue' => (int) $f['revenue'],
        ], $generatedAt);
 
        $this->replace('scent_daily_sales_reports', $rows);
    }
 
    private function ingestTransactions(string $path, Carbon $generatedAt): void
    {
        $rows = $this->parse($path, 'transactions', fn (array $f) => [
            'order_id' => (int) $f['order_id'],
            'date' => $f['date'],
            'customer_name' => $f['customer'] !== '' ? $f['customer'] : null,
            'total_amount' => (int) $f['amount'],
            'item_count' => (int) $f['items'],
            'status' => $f['status'] !== '' ? $f['status'] : 'pending',
            'payment_method' => $f['payment'] !== '' ? $f['payment'] : null,
        ], $generatedAt);
 
        $this->replace('order_sales_reports', $rows);
    }
 
    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------
 
    /**
     * Slice one fixed-width file into rows using a named layout.
     *
     * Every field is trimmed, so PIC X padding spaces and PIC 9 leading
     * zeros both come out clean. The $map callback turns the raw field
     * bag into a DB row; timestamps are stamped here so no caller forgets.
     */
    private function parse(string $path, string $layout, callable $map, Carbon $generatedAt): array
    {
        if (! is_file($path)) {
            $this->warn("Missing COBOL output file: {$path}");
 
            return [];
        }
 
        $spec = self::LAYOUTS[$layout];
        $rows = [];
 
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (trim($line) === '') {
                continue;
            }
 
            $fields = [];
            foreach ($spec as $name => [$start, $length]) {
                $fields[$name] = trim(substr($line, $start, $length));
            }
 
            $rows[] = $map($fields) + [
                'generated_at' => $generatedAt,
                'created_at' => $generatedAt,
                'updated_at' => $generatedAt,
            ];
        }
 
        return $rows;
    }
 
    /**
     * Swap a report table's contents for a freshly-computed set.
     *
     * delete() rather than truncate() because truncate implicitly commits
     * on MySQL, which would break the surrounding transaction and leave
     * the report tables half-rebuilt if a later file failed to parse.
     */
    private function replace(string $table, array $rows): void
    {
        DB::table($table)->delete();
 
        if ($rows === []) {
            return;
        }
 
        // Chunked so a long history doesn't blow past max_allowed_packet
        // or the placeholder limit on a single INSERT.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
 
        $this->line("  {$table}: ".count($rows).' rows');
    }
}
 
 