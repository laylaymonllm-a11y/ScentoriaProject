<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_sales_reports', function (Blueprint $table) {
            // 20 chars matches PIC X(20) in the COBOL record — wide enough for
            // 'virtual_currency' (16), which a 10-char field would truncate.
            $table->string('payment_method', 20)->nullable()->after('status');
            $table->index('payment_method', 'osr_payment_method_index');
        });
    }
 
    public function down(): void
    {
        Schema::table('order_sales_reports', function (Blueprint $table) {
            $table->dropIndex('osr_payment_method_index');
            $table->dropColumn('payment_method');
        });
    }
};
 
 