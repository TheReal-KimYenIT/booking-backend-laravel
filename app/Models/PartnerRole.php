<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerRole extends Model
{
    protected $fillable = [
        'owner_id',
        'name',
        'permissions'
    ];

    protected $casts = [
        'permissions' => 'array', // Laravel tự động chuyển JSON thành Mảng khi gọi ra
    ];

    // Quan hệ: 1 Nhóm quyền có nhiều Nhân viên
    public function staffs()
    {
        return $this->hasMany(Partner::class, 'role_id', 'id');
    }
}
