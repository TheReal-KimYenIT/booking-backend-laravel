<?php
$rt = \App\Models\RoomType::find(70);
echo "RoomType_ID: {$rt->id}, Physical_Rooms: {$rt->rooms()->count()}\n";

$bookings = \App\Models\BookingDetail::where('room_type_id', $rt->id)
    ->whereHas('booking', function($q) {
        $q->whereIn('status', [1, 2]);
    })->with('booking')->get();

foreach($bookings as $bd) {
    echo "Booking ID: {$bd->booking_id}, Status: {$bd->booking->status}, CheckIn: {$bd->booking->check_in}, CheckOut: {$bd->booking->check_out}, Rooms: {$bd->rooms_count}\n";
}

$inventory = \App\Models\RoomInventory::where('room_type_id', $rt->id)
    ->where('apply_date', '>=', '2026-08-17')
    ->where('apply_date', '<=', '2026-08-21')
    ->get();
foreach($inventory as $inv) {
    echo "Date: " . (is_string($inv->apply_date) ? $inv->apply_date : $inv->apply_date->format('Y-m-d')) . ", Available: {$inv->available_allotment}, Is_Closed: {$inv->is_closed}\n";
}
