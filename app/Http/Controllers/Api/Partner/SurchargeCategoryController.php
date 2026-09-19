<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\SurchargeCategory;
use Illuminate\Http\Request;

class SurchargeCategoryController extends Controller
{
    // 1. Lấy danh sách phụ thu
    public function index(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        $categories = SurchargeCategory::where('hotel_id', $hotelId)->get();
        return response()->json(['data' => $categories], 200);
    }

    // 2. Thêm mới danh mục phụ thu
    public function store(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string'
        ]);

        $category = SurchargeCategory::create([
            'hotel_id' => $hotelId,
            'name' => $request->name,
            'description' => $request->description
        ]);

        return response()->json(['message' => 'Thêm loại phụ thu thành công!', 'data' => $category], 201);
    }

    // 3. Cập nhật danh mục phụ thu
    public function update(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();

        $category = SurchargeCategory::where('id', $id)->where('hotel_id', $hotelId)->first();
        if (!$category) return response()->json(['message' => 'Không tìm thấy danh mục'], 404);

        $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string'
        ]);

        $category->update([
            'name' => $request->name,
            'description' => $request->description
        ]);

        return response()->json(['message' => 'Cập nhật thành công!'], 200);
    }

    // 4. Xóa danh mục phụ thu
    public function destroy(int $id)
    {
        $hotelId = $this->getHotelId();

        $category = SurchargeCategory::where('id', $id)->where('hotel_id', $hotelId)->first();
        if (!$category) return response()->json(['message' => 'Không tìm thấy danh mục'], 404);

        // FIX BUG: Chặn xóa nếu danh mục này đã từng được gán cho khách. Việc xóa sẽ làm mất lịch sử tài chính của Booking.
        $inUseCount = \App\Models\BookingSurcharge::where('surcharge_category_id', $id)->count();
        if ($inUseCount > 0) {
            return response()->json([
                'message' => "Không thể xóa! Danh mục này đang được dùng trong {$inUseCount} hóa đơn phụ thu của khách. Xóa sẽ làm hỏng dữ liệu tài chính."
            ], 400);
        }

        $category->delete();

        return response()->json(['message' => 'Xóa loại phụ thu thành công!'], 200);
    }
}
