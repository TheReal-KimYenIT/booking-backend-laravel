<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Amenity;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    // Lấy danh sách tiện nghi thuộc loại phòng.
    public function getRoomAmenities(Request $request)
    {
        $amenities = Amenity::where('type', 2)->get();
        return response()->json(['data' => $amenities], 200);
    }

    // Lấy danh sách phòng vật lý của khách sạn hiện tại.
    public function getRooms(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin'], 400);

        $rooms = Room::with('roomType')
            ->where('hotel_id', $hotelId)
            ->orderBy('room_name', 'asc')
            ->get();

        return response()->json(['data' => $rooms], 200);
    }

    // Tạo mới một phòng vật lý cho khách sạn.
    public function storeRoom(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'room_name' => 'required|string|max:50',
        ]);

        $existingRoom = Room::where('hotel_id', $hotelId)
            ->where('room_name', $request->room_name)
            ->first();

        if ($existingRoom) {
            // Kiểm tra xem Loại phòng cũ của phòng này đã bị xóa chưa
            $oldRoomType = \App\Models\RoomType::find($existingRoom->room_type_id);
            if ($oldRoomType && $oldRoomType->status == 0) {
                // Tái sử dụng phòng cũ
                $existingRoom->room_type_id = $request->room_type_id;
                $existingRoom->status = $request->status ?? 1;
                $existingRoom->save();
                return response()->json(['message' => 'Đã khôi phục và chuyển phòng sang loại phòng mới!'], 201);
            }
            return response()->json(['message' => 'Số phòng này đã tồn tại trên sơ đồ ở một loại phòng khác!'], 400);
        }

        $room = new Room();
        $room->hotel_id = $hotelId;
        $room->room_type_id = $request->room_type_id;
        $room->room_name = $request->room_name;
        $room->status = $request->status ?? 1;
        $room->save();

        return response()->json(['message' => 'Thêm số phòng vật lý thành công!'], 201);
    }

    // Cập nhật thông tin phòng như tên, loại phòng hoặc trạng thái.
    public function updateRoom(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();

        $room = Room::where('id', $id)->where('hotel_id', $hotelId)->first();
        if (!$room) return response()->json(['message' => 'Không tìm thấy phòng'], 404);

        if ($request->has('room_name')) {
            $existingRoom = Room::where('hotel_id', $hotelId)
                ->where('room_name', $request->room_name)
                ->where('id', '!=', $id)
                ->first();

            if ($existingRoom) {
                return response()->json(['message' => 'Số phòng này đã bị trùng với một phòng đang tồn tại! Vui lòng chọn số khác!'], 400);
            }
            $room->room_name = $request->room_name;
        }

        if ($request->has('room_type_id')) $room->room_type_id = $request->room_type_id;
        if ($request->has('status')) $room->status = $request->status;

        $room->save();
        return response()->json(['message' => 'Cập nhật thông tin phòng thành công!'], 200);
    }

    // Xóa phòng khỏi hệ thống nếu không đang có khách ở.
    public function deleteRoom(int $id)
    {
        $hotelId = $this->getHotelId();

        $room = Room::where('id', $id)->where('hotel_id', $hotelId)->first();
        if (!$room) return response()->json(['message' => 'Không tìm thấy phòng'], 404);

        if ($room->status == 2) return response()->json(['message' => 'Không thể xóa phòng đang có khách ở!'], 400);
        $room->delete();
        return response()->json(['message' => 'Đã xóa phòng khỏi hệ thống!'], 200);
    }

    // Lấy danh sách phòng trống theo loại phòng để dùng cho đặt phòng hoặc gán phòng.
    public function getAvailableRoomsByType(int $roomTypeId)
    {
        try {
            $rooms = \App\Models\Room::where('room_type_id', $roomTypeId)
                ->where('status', 1) // Chỉ lấy phòng Trống (1)
                ->get();
            return response()->json($rooms, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Lỗi: ' . $e->getMessage()], 500);
        }
    }
}
