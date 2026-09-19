<?php

namespace App\Http\Controllers\Api\PublicArea;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    // Lấy thông tin chi tiết một loại phòng để hiển thị ở giao diện checkout.
    public function show(int $id)
    {
        // Lấy thông tin loại phòng kèm tiện nghi, khách sạn và ảnh.
        $roomType = RoomType::with(['amenities', 'hotel', 'media'])->find($id);

        // Nếu không tìm thấy hoặc phòng đang tạm ngưng mở bán thì trả về lỗi 404.
        if (!$roomType || $roomType->status != 1) {
            return response()->json(['message' => 'Loại phòng này không tồn tại hoặc đang tạm ngưng mở bán'], 404);
        }

        return response()->json([
            'message' => 'Lấy thông tin phòng thành công',
            'data' => $roomType
        ], 200);
    }
}
