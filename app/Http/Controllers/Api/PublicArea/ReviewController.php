<?php

namespace App\Http\Controllers\Api\PublicArea;

use App\Http\Controllers\Controller;
use App\Models\Review;

class ReviewController extends Controller
{
    // Lấy danh sách đánh giá đã được hiển thị của một khách sạn.
    public function index(int $hotel_id)
    {
        // Lấy đánh giá có status = 1 (Được phép hiển thị)
        // Kèm theo ảnh của đánh giá đó (mối quan hệ 'images')
        // Bảo mật: Chỉ lấy id, first_name, last_name của khách hàng để tránh lộ SĐT, Email
        $reviews = Review::with(['images', 'customer:id,first_name,last_name'])
            ->where('hotel_id', $hotel_id)
            ->where('status', 1)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $reviews
        ]);
    }
}
