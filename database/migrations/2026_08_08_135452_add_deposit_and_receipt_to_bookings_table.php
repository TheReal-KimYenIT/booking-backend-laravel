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
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('deposit_amount', 15, 2)->default(0)->after('total_amount')->comment('So tien coc 50% VNPay');
            $table->string('refund_receipt_url', 255)->nullable()->after('refund_account_name')->comment('Link anh bill hoan tien cua Doi tac');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['deposit_amount', 'refund_receipt_url']);
        });
    }
};
