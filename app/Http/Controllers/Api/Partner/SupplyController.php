<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Supply;
use Illuminate\Http\Request;

class SupplyController extends Controller
{
    public function getSupplies(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        // Lấy từ bảng supplies thay vì services
        $supplies = Supply::where('hotel_id', $hotelId)->orderBy('id', 'desc')->get();
        return response()->json(['data' => $supplies], 200);
    }

    public function storeSupply(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        $request->validate([
            'name' => 'required|string|max:100',
            // FIX BUG: Chống nhập số âm để khách không được 'hoàn tiền' ngược lại khi làm hỏng đồ
            'price' => 'required|numeric|min:0|max:99999999' 
        ]);

        $supply = new Supply();
        $supply->hotel_id = $hotelId; // Dùng trực tiếp $hotelId
        $supply->name = $request->name;
        $supply->price_per_unit = $request->price;
        $supply->status = 1;
        $supply->save();

        return response()->json(['message' => 'Đã thêm vật tư thành công!'], 201);
    }

    public function updateSupply(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();

        $supply = Supply::where('id', $id)->where('hotel_id', $hotelId)->first();
        if (!$supply) return response()->json(['message' => 'Không tìm thấy vật tư'], 404);

        // FIX BUG: Bổ sung Validate cho hàm Update (trước đó quên không có)
        $request->validate([
            'name' => 'nullable|string|max:100',
            'price' => 'nullable|numeric|min:0|max:99999999',
            'status' => 'nullable|in:0,1'
        ]);

        if ($request->has('name')) $supply->name = $request->name;
        if ($request->has('price')) $supply->price_per_unit = $request->price;
        if ($request->has('status')) $supply->status = $request->status;
        $supply->save();

        return response()->json(['message' => 'Cập nhật vật tư thành công!'], 200);
    }

    public function deleteSupply(int $id)
    {
        $hotelId = $this->getHotelId();

        $supply = Supply::where('id', $id)->where('hotel_id', $hotelId)->first();
        if (!$supply) return response()->json(['message' => 'Không tìm thấy vật tư'], 404);

        if ($supply->incidents()->exists()) {
            return response()->json([
                'message' => 'Không thể xóa vĩnh viễn vì vật tư này đã có trong lịch sử sự cố đền bù! Bạn hãy chọn "Ngừng áp dụng" để dừng tính phí.'
            ], 400);
        }

        $supply->delete();

        return response()->json(['message' => 'Đã xóa vật tư khỏi danh mục thành công!'], 200);
    }
}
