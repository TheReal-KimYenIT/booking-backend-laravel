<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Models\Media;

class RoomType extends Model
{
    public $timestamps = false;

    protected $table = 'room_types';

    protected $fillable = [
        'hotel_id',
        'name',
        'slug',
        'room_size',
        'base_price',
        'max_adults',
        'max_children',
        'status',
        'view_id',
        'bed_type_id',
        'description',
        'has_breakfast',
        'smoking_policy',

        'free_cancel_hours',
        'partial_refund_hours',
        'partial_refund_percent'
    ];
    // Tự động tạo slug (đường dẫn chuẩn SEO) từ tên loại phòng khi lưu
    protected static function boot()
    {
        parent::boot();
        static::saving(function ($roomType) {
            if (empty($roomType->slug)) {
                $roomType->slug = Str::slug($roomType->name) . '-' . time();
            }
        });
    }

    // Liên kết với bảng tiện ích
    public function amenities()
    {
        return $this->belongsToMany(Amenity::class, 'room_type_amenity', 'room_type_id', 'amenity_id');
    }

    // Liên kết ngược lại với khách sạn
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }

    //  Liên kết với bảng Media để lấy hình ảnh phòng 
    public function media()
    {
        return $this->hasMany(Media::class, 'model_id')->where('model_type', 'RoomType');
    }

    public function roomView()
    {
        return $this->belongsTo(RoomView::class, 'view_id');
    }

    //   Mối quan hệ trỏ tới danh mục Loại Giường của Admin 
    public function bedTypeDetail()
    {
        return $this->belongsTo(BedType::class, 'bed_type_id');
    }

    public function bookingDetails()
    {
        return $this->hasMany(BookingDetail::class, 'room_type_id');
    }

    public function rooms()
    {
        return $this->hasMany(Room::class, 'room_type_id');
    }

    public function roomInventories()
    {
        return $this->hasMany(RoomInventory::class, 'room_type_id');
    }
}
