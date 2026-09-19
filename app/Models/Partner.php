<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;

class Partner extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // Cho phép tất cả các cột được insert ngoại trừ id
    protected $guarded = ['id'];

    // Giấu password khi trả dữ liệu qua API
    protected $hidden = ['password_hash'];

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    // Quan hệ: 1 Đối tác (Owner) có nhiều Khách sạn
    public function hotel()
    {
        return $this->hasOne(Hotel::class, 'partner_id', 'id');
    }

    // Quan hệ: 1 Owner có nhiều Staffs (Lễ tân/Quản lý)
    public function staffs()
    {
        return $this->hasMany(Partner::class, 'parent_id', 'id');
    }

    // Quan hệ: 1 Staff thuộc về 1 Owner (Lấy ra sếp của mình)
    public function owner()
    {
        return $this->belongsTo(Partner::class, 'parent_id', 'id');
    }

    public function role()
    {
        return $this->belongsTo(PartnerRole::class, 'role_id');
    }
}
