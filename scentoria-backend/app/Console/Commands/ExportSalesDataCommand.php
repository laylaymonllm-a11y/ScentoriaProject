<?php
 
namespace App\Console\Commands;
 
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
 
class ExportSalesDataCommand extends Command
{
    protected $signature = 'sales:export';
    protected $description = 'Export order line items to a fixed-width file for the COBOL sales batch job';
 
    // Field widths — must stay in sync with the record layout
    // (01 INPUT-RECORD) in cobol/SALESBATCH.cob. Total = 171 chars.
    private const DATE_LEN = 8;
    private const ORDER_ID_LEN = 8;
    private const CUSTOMER_LEN = 30;
    private const PRODUCT_LEN = 30;
    private const BRAND_LEN = 20;
    private const SCENT_LEN = 20;
    private const QTY_LEN = 5;
    private const LINE_TOTAL_LEN = 10;
    private const ORDER_TOTAL_LEN = 10;
    private const STATUS_LEN = 10;
    // 20, not 10: 'virtual_currency' is 16 characters.
    private const PAYMENT_LEN = 20;
 
    public function handle(): int
    {
        $path = storage_path('app/cobol/input/sales_input.dat');
 
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
 
        // Every status is exported, not just 'completed'. The batch job
        // writes all of them to the transaction audit log but only counts
        // 'completed' toward revenue — see the REVENUE RECOGNITION note in
        // cobol/SALESBATCH.cob. Filtering here instead would leave the
        // report's transaction table unable to show anything but completed
        // orders, which defeats the point of an audit log.
        //
        // The ORDER BY is load-bearing: SALESBATCH.cob uses control-break
        // processing and assumes records arrive grouped by date ascending.
        // Removing it silently produces duplicate day rows.
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->join('scents', 'scents.id', '=', 'products.scent_id')
            ->orderBy('orders.created_at')
            ->orderBy('orders.id')
            ->select([
                'orders.id as order_id',
                'orders.created_at',
                'orders.total_amount as order_total',
                'orders.status',
                'orders.payment_method',
                'users.name as customer_name',
                'products.name as product_name',
                'brands.name as brand_name',
                'scents.name as scent_name',
                'order_items.quantity',
                'order_items.price',
            ])
            ->get();
 
        $handle = fopen($path, 'w');
 
        foreach ($rows as $row) {
            $date = Carbon::parse($row->created_at)->format('Ymd');
            $lineTotal = (int) $row->quantity * (int) $row->price;
 
            $record =
                $this->text($date, self::DATE_LEN).
                $this->number($row->order_id, self::ORDER_ID_LEN).
                $this->text($row->customer_name, self::CUSTOMER_LEN).
                $this->text($row->product_name, self::PRODUCT_LEN).
                $this->text($row->brand_name, self::BRAND_LEN).
                $this->text($row->scent_name, self::SCENT_LEN).
                $this->number($row->quantity, self::QTY_LEN).
                $this->number($lineTotal, self::LINE_TOTAL_LEN).
                $this->number($row->order_total, self::ORDER_TOTAL_LEN).
                $this->text($row->status, self::STATUS_LEN).
                $this->text($row->payment_method, self::PAYMENT_LEN);
 
            fwrite($handle, $record.PHP_EOL);
        }
 
        fclose($handle);
 
        $this->info("Exported {$rows->count()} line items to {$path}");
 
        return self::SUCCESS;
    }
 
    /**
     * PIC X(n): left-justified, space-padded, hard-truncated.
     *
     * mb_substr rather than substr so a multi-byte customer or product
     * name can't be cut mid-character — a half character would push every
     * following field out of alignment and COBOL would read garbage.
     */
    private function text(?string $value, int $length): string
    {
        $value = mb_substr((string) $value, 0, $length);
 
        // Byte length is what matters to a fixed-width reader, so pad on
        // bytes and trim byte-wise if a multi-byte name still overflows.
        $value = substr($value, 0, $length);
 
        return str_pad($value, $length, ' ', STR_PAD_RIGHT);
    }
 
    /**
     * PIC 9(n): right-justified, zero-padded. Negative or oversized values
     * are clamped rather than written wide, because one long field would
     * shift every subsequent field in the record.
     */
    private function number(int|string|null $value, int $length): string
    {
        $value = max(0, (int) $value);
        $digits = (string) $value;
 
        if (strlen($digits) > $length) {
            $digits = str_repeat('9', $length);
        }
 
        return str_pad($digits, $length, '0', STR_PAD_LEFT);
    }
}
 
 