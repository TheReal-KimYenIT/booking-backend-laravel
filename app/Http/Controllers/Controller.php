<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    //Lấy ra ID của Chủ Khách Sạn (Dù người đang đăng nhập là Lễ tân)
    protected function getOwnerId()
    {
        $user = Auth::guard('partner')->user();
        if (!$user) return null;

        // Nếu là Owner (role = 1) hoặc không có parent -> Chính là nó
        if ($user->role_id == 1 || is_null($user->parent_id)) {
            return $user->id;
        }

        // Nếu là Nhân viên -> Lấy ID của Sếp
        return $user->parent_id;
    }

    //  Lấy ra ID Khách sạn dựa trên Sếp
    protected function getHotelId()
    {
        $ownerId = $this->getOwnerId();
        if (!$ownerId) return null;

        $hotel = \App\Models\Hotel::where('partner_id', $ownerId)->first();
        return $hotel ? $hotel->id : null;
    }
}
