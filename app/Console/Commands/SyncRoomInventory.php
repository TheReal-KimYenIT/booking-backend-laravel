<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\RoomType;
use App\Models\RoomInventory;
use App\Models\BookingDetail;
use Carbon\Carbon;

class SyncRoomInventory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'booking:sync-inventory';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync room inventories available allotment based on physical rooms and bookings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting inventory sync...');

        $roomTypes = RoomType::all();
        $today = Carbon::today()->format('Y-m-d');

        foreach ($roomTypes as $roomType) {
            $totalPhysicalRooms = $roomType->rooms()->count();

            // Get all inventories for this room type from today onwards
            $inventories = RoomInventory::where('room_type_id', $roomType->id)
                ->where('apply_date', '>=', $today)
                ->get();

            foreach ($inventories as $inv) {
                $dateStr = $inv->apply_date instanceof Carbon
                    ? $inv->apply_date->format('Y-m-d')
                    : Carbon::parse($inv->apply_date)->format('Y-m-d');

                // Find active bookings covering this date
                $bookedRooms = BookingDetail::where('room_type_id', $roomType->id)
                    ->whereHas('booking', function ($q) use ($dateStr) {
                        $q->whereIn('status', [1, 2]) // Confirmed or Checked-in
                            ->where('check_in', '<=', $dateStr)
                            ->where('check_out', '>', $dateStr);
                    })->sum('rooms_count');

                $newAllotment = max(0, $totalPhysicalRooms - $bookedRooms);

                if ($inv->available_allotment !== $newAllotment) {
                    $this->info("RoomType {$roomType->id} on {$dateStr}: changing allotment from {$inv->available_allotment} to {$newAllotment}");
                    $inv->available_allotment = $newAllotment;
                    $inv->save();
                }
            }
        }

        $this->info('Inventory sync completed successfully.');
    }
}
