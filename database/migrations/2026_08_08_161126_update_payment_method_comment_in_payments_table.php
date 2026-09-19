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
            $table->tinyInteger('payment_method')->unsigned()->comment('1: Tiền mặt, 2: Quẹt thẻ POS, 3: Chuyển khoản thủ công tại quầy, 4: VNPay')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->tinyInteger('payment_method')->unsigned()->comment('1: Tiền mặt, 2: Quẹt thẻ POS, 3: Chuyển khoản thủ công tại quầy')->change();
        });
    }
};
