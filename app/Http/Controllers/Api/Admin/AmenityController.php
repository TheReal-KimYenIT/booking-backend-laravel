<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Amenity;
use Illuminate\Support\Facades\DB;

class AmenityController extends Controller
{
    // Lấy danh sách tiện ích để admin hiển thị ở màn hình quản lý.
    public function index()
    {
        $amenities = Amenity::withCount(['hotels', 'roomTypes'])
            ->orderBy('id', 'desc')
            ->get();
        return response()->json(['data' => $amenities], 200);
    }

    // Tạo mới một tiện ích cho khách sạn hoặc phòng.
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100|unique:amenities,name',
            'type' => 'required|integer|in:1,2', // 1: Hotel, 2: Room
            'icon' => 'nullable|string|max:100'
        ], [
            'name.required' => 'Vui lòng nhập tên tiện ích!',
            'name.unique' => 'Tên tiện ích này đã tồn tại trên hệ thống!',
            'type.required' => 'Vui lòng chọn phân loại tiện ích!',
            'type.in' => 'Phân loại tiện ích không hợp lệ!'
        ]);

        $amenity = new Amenity();
        $amenity->name = trim($request->name);
        $amenity->icon = $request->icon ? trim($request->icon) : null;
        $amenity->type = $request->type;
        $amenity->save();

        return response()->json(['message' => 'Thêm tiện ích thành công!', 'data' => $amenity], 201);
    }

    // Cập nhật thông tin tiện ích đã có.
    public function update(Request $request, int $id)
    {
        $request->validate([
            'name' => 'required|string|max:100|unique:amenities,name,' . $id,
            'type' => 'required|integer|in:1,2',
            'icon' => 'nullable|string|max:100'
        ], [
            'name.required' => 'Vui lòng nhập tên tiện ích!',
            'name.unique' => 'Tên tiện ích này đã tồn tại trên hệ thống!',
            'type.required' => 'Vui lòng chọn phân loại tiện ích!',
            'type.in' => 'Phân loại tiện ích không hợp lệ!'
        ]);

        $amenity = Amenity::findOrFail($id);

        // Chặn đổi phân loại nếu tiện ích đang được liên kết thực tế trong hệ thống
        if ($amenity->type != $request->type) {
            $usedInHotels = DB::table('hotel_amenity')->where('amenity_id', $id)->exists();
            $usedInRooms = DB::table('room_type_amenity')->where('amenity_id', $id)->exists();

            if ($usedInHotels || $usedInRooms) {
                return response()->json([
                    'message' => 'Không thể đổi phân loại vì tiện ích này đang được liên kết với khách sạn hoặc phòng!'
                ], 422);
            }
        }

        $amenity->name = trim($request->name);
        $amenity->icon = $request->icon ? trim($request->icon) : null;
        $amenity->type = $request->type;
        $amenity->save();

        return response()->json(['message' => 'Cập nhật tiện ích thành công!'], 200);
    }

    // Xóa tiện ích nếu không còn được dùng.
    public function destroy(int $id)
    {
        try {
            $amenity = Amenity::findOrFail($id);

            $usedInHotels = DB::table('hotel_amenity')->where('amenity_id', $id)->exists();
            $usedInRooms = DB::table('room_type_amenity')->where('amenity_id', $id)->exists();

            if ($usedInHotels || $usedInRooms) {
                return response()->json([
                    'message' => 'Không thể xóa vì tiện ích này đang được khách sạn hoặc phòng sử dụng!'
                ], 400);
            }

            $amenity->delete();
            return response()->json(['message' => 'Xóa tiện ích thành công!'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
}
