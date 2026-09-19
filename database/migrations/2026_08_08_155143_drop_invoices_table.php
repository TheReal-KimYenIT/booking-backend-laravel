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
        Schema::dropIfExists('invoices');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('booking_id');
            $table->string('tax_code', 50);
            $table->string('company_name', 255);
            $table->text('company_address');
            $table->string('receiving_email', 100);
            $table->tinyInteger('status')->default(0)->comment('0: Chờ xuất, 1: Đã xuất');
            $table->timestamps();
        });
    }
};
