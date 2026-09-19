<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomInventory extends Model
{
    use HasFactory;

    protected $table = 'room_inventory';

    public $timestamps = false;

    protected $fillable = [
        'room_type_id',
        'apply_date',
        'price',
        'available_allotment',
        'is_closed'
    ];

    protected $casts = [
        'apply_date' => 'date',
        'price' => 'decimal:2',
        'available_allotment' => 'integer',
        'is_closed' => 'integer'
    ];

    /**
     * Mối quan hệ: Bản ghi lịch này thuộc về một Hạng phòng (RoomType)
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id', 'id');
    }
}
