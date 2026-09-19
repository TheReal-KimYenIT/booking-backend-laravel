<?php

namespace App\Http\Controllers\Api\Customer; // ĐÃ CẬP NHẬT: Trỏ vào đúng thư mục Customer

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FavoriteController extends Controller
{
    // Lấy danh sách khách sạn mà khách hàng đã đánh dấu yêu thích.
    public function getFavorites(Request $request)
    {
        $user = $request->user();

        // Lấy danh sách khách sạn yêu thích kèm theo hình ảnh.
        $favorites = $user->favoriteHotels()->with(['images'])->get();

        return response()->json([
            'message' => 'Lấy danh sách yêu thích thành công',
            'data' => $favorites
        ], 200);
    }

    // Thêm hoặc bỏ khách sạn khỏi danh sách yêu thích.
    public function toggleFavorite(Request $request, int $hotelId)
    {
        $user = $request->user();
        
        $result = $user->favoriteHotels()->toggle($hotelId);
        $isFavorite = count($result['attached']) > 0;
        
        $message = $isFavorite ? 'Đã thêm vào danh sách yêu thích' : 'Đã bỏ yêu thích khách sạn';

        return response()->json(['message' => $message, 'is_favorite' => $isFavorite], 200);
    }
}
