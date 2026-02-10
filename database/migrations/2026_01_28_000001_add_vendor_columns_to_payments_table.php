<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Track which payment processor handled the payment
            $table->string('payment_vendor')->nullable()->after('payment_method_last_four');
            
            // The transaction ID from the vendor (Kotapay or MiPaymentChoice)
            $table->string('vendor_transaction_id')->nullable()->after('payment_vendor');
            
            // Index for querying by vendor
            $table->index('payment_vendor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payment_vendor']);
            $table->dropColumn(['payment_vendor', 'vendor_transaction_id']);
        });
    }
};
