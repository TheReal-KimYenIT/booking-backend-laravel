<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Amenity extends Model
{
    public $timestamps = false;
    protected $fillable = ['name', 'icon', 'type'];

    public function hotels()
    {
        return $this->belongsToMany(Hotel::class, 'hotel_amenity', 'amenity_id', 'hotel_id');
    }

    public function roomTypes()
    {
        return $this->belongsToMany(RoomType::class, 'room_type_amenity', 'amenity_id', 'room_type_id');
    }
}
