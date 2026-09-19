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
        Schema::table('chat_threads', function (Blueprint $table) {
            $table->dropForeign('fk_threads_booking');
            $table->dropColumn(['booking_id', 'subject']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_threads', function (Blueprint $table) {
            $table->unsignedInteger('booking_id')->nullable();
            $table->string('subject', 255)->nullable();

            $table->foreign('booking_id', 'fk_threads_booking')
                ->references('id')
                ->on('bookings')
                ->onDelete('set null');
        });
    }
};
