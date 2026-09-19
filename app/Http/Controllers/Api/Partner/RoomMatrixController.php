<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\BookingRoomAssignment;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class RoomMatrixController extends Controller
{
    public function getMatrix(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Không tìm thấy thông tin khách sạn'], 400);

        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        // 1. Tạo mảng header ngày tháng cho trục ngang
        $period = CarbonPeriod::create($startDate, $endDate);
        $daysHeader = [];
        foreach ($period as $date) {
            $daysHeader[] = [
                'date_string' => $date->format('Y-m-d'),
                'day_name' => $date->locale('vi')->minDayName, // Thứ 2, Thứ 3...
                'day_label' => $date->format('d/m')
            ];
        }

        // 2. Lấy toàn bộ danh sách phòng vật lý của khách sạn thuộc trục dọc
        $rooms = Room::with('roomType')
            ->where('hotel_id', $hotelId)
            ->orderBy('room_name', 'asc')
            ->get();

        // 3. Lấy toàn bộ lịch xếp phòng đang diễn ra trong dải ngày này (loại trừ đơn đã hủy)
        $startDateStr = $startDate->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');

        $assignments = BookingRoomAssignment::with(['booking'])
            ->whereHas('booking', function ($query) use ($hotelId, $startDateStr, $endDateStr) {
                $query->where('hotel_id', $hotelId)
                      ->whereIn('status', [1, 2, 3]) // CHỈ LẤY: 1: Đã xác nhận, 2: Đang lưu trú, 3: Đã trả phòng (Loại trừ 4: Đã hủy, 5: No-show, 0: Chưa thanh toán)
                      ->where('check_in', '<=', $endDateStr)
                      ->where('check_out', '>', $startDateStr); // CÔNG THỨC TOÁN HỌC GIAO THỜI GIAN
            })
            ->get();

        $matrixGrid = [];

        foreach ($rooms as $room) {
            $row = [
                'room_id' => $room->id,
                'room_name' => $room->room_name,
                'room_type_name' => $room->roomType->name ?? 'Mặc định',
                'current_status' => $room->status, // Tình trạng buồng phòng hiện tại (0: dọn dẹp, 1: trống, 2: có khách, 3: bảo trì)
                'days' => []
            ];

            foreach ($period as $date) {
                $dateStr = $date->format('Y-m-d');

                // Tìm xem ngày này phòng này có khách ở không thông qua bảng trung gian nối
                $assigned = $assignments->first(function ($item) use ($room, $dateStr) {
                    return $item->room_id === $room->id
                        && $dateStr >= $item->booking->check_in
                        && $dateStr < $item->booking->check_out; // Không tính ngày checkout vì phòng sẽ trống chiều hôm đó
                });

                if ($assigned && $assigned->booking) {
                    $row['days'][] = [
                        'date' => $dateStr,
                        'is_occupied' => true,
                        'booking_id' => $assigned->booking->id,
                        'booking_code' => $assigned->booking->booking_code,
                        'guest_name' => $assigned->booking->guest_name,
                        'status' => $assigned->booking->status // Trạng thái đơn để tô màu (0, 1, 2, 3)
                    ];
                } else {
                    $row['days'][] = [
                        'date' => $dateStr,
                        'is_occupied' => false,
                        'booking_id' => null,
                        'booking_code' => null,
                        'guest_name' => null,
                        'status' => null
                    ];
                }
            }

            $matrixGrid[] = $row;
        }

        // Thống kê nhanh tình trạng buồng phòng vật lý
        $stats = [
            'total' => $rooms->count(),
            'ready' => $rooms->where('status', 1)->count(),
            'occupied' => $rooms->where('status', 2)->count(),
            'cleaning' => $rooms->where('status', 0)->count(),
            'maintenance' => $rooms->where('status', 3)->count(),
        ];

        return response()->json([
            'headers' => $daysHeader,
            'matrix' => $matrixGrid,
            'stats' => $stats
        ], 200);
    }
}
