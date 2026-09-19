<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingDetail extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'room_type_id',
        'rooms_count',
        'subtotal'
    ];
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }
    // Liên kết để biết chi tiết này đặt loại phòng nào
    public function roomType()
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }
}
