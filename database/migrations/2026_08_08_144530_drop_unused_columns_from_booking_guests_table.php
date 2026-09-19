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
            $table->dropForeign('fk_bguest_assignment');
            $table->dropColumn(['room_assignment_id', 'gender', 'nationality']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_guests', function (Blueprint $table) {
            $table->unsignedInteger('room_assignment_id')->nullable();
            $table->tinyInteger('gender')->unsigned()->nullable()->default(1);
            $table->string('nationality', 50)->nullable()->default('Vietnam');
            
            $table->foreign('room_assignment_id', 'fk_bguest_assignment')
                ->references('id')
                ->on('booking_room_assignments')
                ->onDelete('set null');
        });
    }
};
