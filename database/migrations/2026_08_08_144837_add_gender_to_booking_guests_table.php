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
        Schema::table('booking_guests', function (Blueprint $table) {
            $table->tinyInteger('gender')->unsigned()->nullable()->default(1)->comment('1: Nam, 2: Nu, 3: Khac');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_guests', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
