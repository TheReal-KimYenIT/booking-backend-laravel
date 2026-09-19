<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BedType extends Model
{
    protected $table = 'bed_types';

    protected $fillable = [
        'name',
        'status',
    ];

    public function roomTypes()
    {
        return $this->hasMany(RoomType::class, 'bed_type_id');
    }
}
