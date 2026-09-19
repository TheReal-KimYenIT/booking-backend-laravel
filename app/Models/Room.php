<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;

class Room extends Model
{
    use HasFactory;
    protected $guarded = [];
    public $timestamps = false;
    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
    public function roomType()
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    protected static function booted()
    {
        // Khi tạo phòng vật lý mới, tăng allotment của tương lai lên 1
        static::created(function ($room) {
            $today = \Carbon\Carbon::today()->format('Y-m-d');
            RoomInventory::where('room_type_id', $room->room_type_id)
                ->where('apply_date', '>=', $today)
                ->increment('available_allotment', 1);
        });

        // Khi xóa phòng vật lý, giảm allotment của tương lai xuống 1 (đảm bảo không âm)
        static::deleted(function ($room) {
            $today = \Carbon\Carbon::today()->format('Y-m-d');
            $inventories = RoomInventory::where('room_type_id', $room->room_type_id)
                ->where('apply_date', '>=', $today)
                ->get();
                
            foreach ($inventories as $inv) {
                if ($inv->available_allotment > 0) {
                    $inv->decrement('available_allotment', 1);
                }
            }
        });
    }
}

