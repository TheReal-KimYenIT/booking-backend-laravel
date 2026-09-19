<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;

class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    protected $fillable = [
        'last_name',
        'first_name',
        'email',
        'phone',
        'gender',
        'dob',
        'address',
        'password_hash',
        'is_active',
    ];

    // Ẩn mật khẩu khi API trả về cục data JSON
    protected $hidden = [
        'password_hash',
    ];

    // Chỉ đường cho Laravel biết cột mật khẩu tên là gì
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    // ==========================================
    // CÁC LIÊN KẾT (RELATIONSHIPS)
    // ==========================================

    public function favoriteHotels()
    {
        return $this->belongsToMany(Hotel::class, 'favorites', 'customer_id', 'hotel_id')->withTimestamps();
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'customer_id');
    }
}
