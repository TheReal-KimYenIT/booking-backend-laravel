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
            $table->dropColumn(['is_vat_requested', 'is_reviewed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->tinyInteger('is_vat_requested')->default(0)->after('commission_rate');
            $table->tinyInteger('is_reviewed')->default(0)->after('payment_status');
        });
    }
};
