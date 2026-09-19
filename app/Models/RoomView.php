<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomView extends Model
{
    protected $table = 'room_views';

    protected $fillable = [
        'name',
        'status',
    ];

    public function roomTypes()
    {
        return $this->hasMany(RoomType::class, 'view_id');
    }
}
