<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    /**
     * Read-side tables for the admin Sales Report.
     *
     * Nothing writes to any of these except RunSalesBatchCommand, after the
     * COBOL job finishes. The React page and the CSV/PDF exports only ever
     * SELECT from here, so a page load never waits on aggregation.
     *
     * WHY THE DAY-GRAIN TABLES
     * The report has a custom date-range picker and every card, chart and
     * table on it has to respond to the chosen range. An all-time product
     * roll-up cannot answer "top sellers between 1 and 14 July", so the
     * COBOL job emits one row per day per dimension and the controller
     * re-sums whatever slice the admin asked for.
     */
    public function up(): void
    {
        // Orders and units come from the batch job now, so AOV
        // (revenue / orders) and "Total Products Sold" can be answered for
        // any date range without touching the live orders tables.
        Schema::table('daily_sales_reports', function (Blueprint $table) {
            $table->unsignedInteger('order_count')->default(0)->after('total_revenue');
            $table->unsignedInteger('units_sold')->default(0)->after('order_count');
        });
 
        Schema::table('monthly_sales_reports', function (Blueprint $table) {
            $table->unsignedInteger('order_count')->default(0)->after('total_revenue');
            $table->unsignedInteger('units_sold')->default(0)->after('order_count');
        });
 
        // One row per day per product. brand_name is denormalised in from
        // the COBOL output rather than joined at read time: the report has
        // to keep showing the brand a product was sold under even if the
        // product is later reassigned or deleted from the catalogue.
        Schema::create('product_daily_sales_reports', function (Blueprint $table) {
            $table->id();
            $table->char('date', 8);              // "20260730"
            $table->string('product_name');
            $table->string('brand_name')->nullable();
            $table->unsignedInteger('quantity_sold')->default(0);
            $table->unsignedBigInteger('revenue')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
 
            $table->unique(['date', 'product_name'], 'pdsr_date_product_unique');
            $table->index('date', 'pdsr_date_index');
        });
 
        // One row per day per scent profile (Floral, Woody, Citrus, ...).
        Schema::create('scent_daily_sales_reports', function (Blueprint $table) {
            $table->id();
            $table->char('date', 8);
            $table->string('scent_name');
            $table->unsignedInteger('quantity_sold')->default(0);
            $table->unsignedBigInteger('revenue')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
 
            $table->unique(['date', 'scent_name'], 'sdsr_date_scent_unique');
            $table->index('date', 'sdsr_date_index');
        });
 
        // Transaction audit log — one row per order, every status included.
        // Deliberately NOT a foreign key to orders: this is a point-in-time
        // snapshot of what the batch job saw, and it should survive an order
        // being deleted rather than disappear from an already-exported
        // accounting report.
        Schema::create('order_sales_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->char('date', 8);
            $table->string('customer_name')->nullable();
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
 
            $table->unique('order_id', 'osr_order_id_unique');
            $table->index('date', 'osr_date_index');
            $table->index('status', 'osr_status_index');
        });
    }
 
    public function down(): void
    {
        Schema::dropIfExists('order_sales_reports');
        Schema::dropIfExists('scent_daily_sales_reports');
        Schema::dropIfExists('product_daily_sales_reports');
 
        Schema::table('monthly_sales_reports', function (Blueprint $table) {
            $table->dropColumn(['order_count', 'units_sold']);
        });
 
        Schema::table('daily_sales_reports', function (Blueprint $table) {
            $table->dropColumn(['order_count', 'units_sold']);
        });
    }
};
 
 