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
        Schema::table('booking_details', function (Blueprint $table) {
            $table->dropColumn(['check_in_date', 'check_out_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_details', function (Blueprint $table) {
            $table->date('check_in_date')->after('room_type_id');
            $table->date('check_out_date')->after('check_in_date');
        });
    }
};
