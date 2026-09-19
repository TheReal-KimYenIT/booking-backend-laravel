<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Models\RoomInventory;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class RoomInventoryController extends Controller
{
    // Lấy ma trận dữ liệu tồn kho & giá phòng theo dải ngày
    public function index(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Không tìm thấy khách sạn.'], 404);

        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $todayStr = Carbon::today()->format('Y-m-d');

        // Tạo mảng danh sách ngày để gửi ra ngoài cho Angular làm Header cột
        $period = CarbonPeriod::create($startDate, $endDate);
        $daysHeader = [];
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $dayOfWeek = $date->dayOfWeek; // 0: CN, 6: T7
            $daysHeader[] = [
                'date_string' => $dateStr,
                'day_name' => $date->locale('vi')->minDayName, // T2, T3, T4...
                'day_label' => $date->format('d/m'),
                'is_today' => ($dateStr === $todayStr),
                'is_weekend' => ($dayOfWeek === 0 || $dayOfWeek === 6)
            ];
        }

        // Lấy toàn bộ hạng phòng của khách sạn (cả đang bán và tạm ngưng để đối tác có thể xem và mở bán lại)
        $roomTypes = RoomType::where('hotel_id', $hotelId)
            ->withCount('rooms')
            ->orderBy('status', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        // Lấy toàn bộ dữ liệu cấu hình đặc biệt trong dải ngày này
        $inventories = RoomInventory::whereIn('room_type_id', $roomTypes->pluck('id'))
            ->whereBetween('apply_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get();

        $inventoryLookup = [];
        foreach ($inventories as $inv) {
            $dateFormatted = $inv->apply_date instanceof Carbon
                ? $inv->apply_date->format('Y-m-d')
                : Carbon::parse($inv->apply_date)->format('Y-m-d');

            $key = $inv->room_type_id . '_' . $dateFormatted;
            $inventoryLookup[$key] = $inv;
        }

        // Tải trước các booking đã xác nhận / đang ở trong khoảng thời gian này để tính allotment
        $bookedDetails = \App\Models\BookingDetail::whereIn('room_type_id', $roomTypes->pluck('id'))
            ->whereHas('booking', function ($q) use ($startDate, $endDate) {
                $q->whereIn('status', [1, 2])
                    ->where('check_in', '<', $endDate->copy()->addDay()->format('Y-m-d'))
                    ->where('check_out', '>', $startDate->format('Y-m-d'));
            })
            ->with('booking:id,status,check_in,check_out')
            ->get();

        $resultGrid = [];

        foreach ($roomTypes as $roomType) {
            $totalRooms = $roomType->rooms_count ?? $roomType->rooms()->count();

            $isRoomTypeActive = ((int)$roomType->status === 1);

            $rowGrid = [
                'room_type_id' => $roomType->id,
                'room_type_name' => $roomType->name,
                'base_price' => (float)$roomType->base_price,
                'total_physical_rooms' => $totalRooms,
                'status' => (int)$roomType->status, // 1: Đang mở bán, 0: Tạm ngưng bán
                'days_data' => []
            ];

            foreach ($period as $date) {
                $dateStr = $date->format('Y-m-d');

                $lookupKey = $roomType->id . '_' . $dateStr;
                $customData = $inventoryLookup[$lookupKey] ?? null;

                // Tính toán tỷ lệ phần trăm và chiều hướng (Trend)
                $price = $customData ? (float)$customData->price : (float)$roomType->base_price;
                $basePrice = (float)$roomType->base_price;
                $isCustom = $customData ? true : false;

                $diff = $price - $basePrice;
                $percentChange = $basePrice > 0 ? round(($diff / $basePrice) * 100) : 0;

                $trend = 'none';
                if ($percentChange > 0) $trend = 'up';
                elseif ($percentChange < 0) $trend = 'down';

                // Tính số phòng trống (Available Allotment)
                if ($customData && !is_null($customData->available_allotment)) {
                    $availableAllotment = (int)$customData->available_allotment;
                } else {
                    $bookedCount = $bookedDetails->where('room_type_id', $roomType->id)
                        ->filter(function ($detail) use ($dateStr) {
                            if (!$detail->booking) return false;
                            $cin = $detail->booking->check_in instanceof Carbon ? $detail->booking->check_in->format('Y-m-d') : substr($detail->booking->check_in, 0, 10);
                            $cout = $detail->booking->check_out instanceof Carbon ? $detail->booking->check_out->format('Y-m-d') : substr($detail->booking->check_out, 0, 10);
                            return ($cin <= $dateStr && $cout > $dateStr);
                        })->sum('rooms_count');

                    $availableAllotment = max(0, $totalRooms - $bookedCount);
                }

                // Nếu hạng phòng đang bị tạm ngưng toàn hệ thống (status = 0), tự động hiển thị khóa bán
                $isClosed = (!$isRoomTypeActive) ? 1 : ($customData ? (int)$customData->is_closed : 0);
                $finalAllotment = (!$isRoomTypeActive) ? 0 : $availableAllotment;

                $rowGrid['days_data'][] = [
                    'date' => $dateStr,
                    'price' => $price,
                    'available_allotment' => $finalAllotment,
                    'total_rooms' => $totalRooms,
                    'is_closed' => $isClosed,
                    'is_custom' => $isCustom,
                    'percent_change' => $percentChange,
                    'trend' => $trend
                ];
            }

            $resultGrid[] = $rowGrid;
        }

        return response()->json([
            'headers' => $daysHeader,
            'grid' => $resultGrid
        ], 200);
    }

    // Cập nhật giá trị (Bulk Update) khi kéo chọn hoặc lưu ô
    public function updateBulk(Request $request)
    {
        $request->validate([
            'room_type_ids' => 'required|array',
            'room_type_ids.*' => 'integer|exists:room_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',

            // Cấu hình cập nhật nâng cao
            'update_type' => 'required|string|in:fixed,percent,reset,none',
            'price_value' => 'nullable|numeric',
            'change_status' => 'required|boolean',
            'is_closed' => 'nullable|boolean',
            'change_allotment' => 'nullable|boolean',
            'allotment_value' => 'nullable|integer|min:0'
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $period = CarbonPeriod::create($startDate, $endDate);

        foreach ($request->room_type_ids as $roomTypeId) {
            $roomType = RoomType::find($roomTypeId);
            if (!$roomType) continue;

            $totalPhysicalRooms = $roomType->rooms()->count();

            foreach ($period as $date) {
                $dateStr = $date->format('Y-m-d');

                // Chế độ RESET: Khôi phục trạng thái mặc định của hệ thống
                if ($request->update_type === 'reset') {
                    $inventory = RoomInventory::where('room_type_id', $roomTypeId)
                        ->where('apply_date', $dateStr)
                        ->first();

                    if ($inventory) {
                        $bookedRooms = \App\Models\BookingDetail::where('room_type_id', $roomTypeId)
                            ->whereHas('booking', function ($q) use ($dateStr) {
                                $q->whereIn('status', [1, 2])
                                    ->where('check_in', '<=', $dateStr)
                                    ->where('check_out', '>', $dateStr);
                            })->sum('rooms_count');

                        $inventory->update([
                            'price' => $roomType->base_price,
                            'available_allotment' => max(0, $totalPhysicalRooms - $bookedRooms),
                            'is_closed' => $request->change_status ? ($request->is_closed ? 1 : 0) : 0
                        ]);
                    }
                    continue;
                }

                // Chế độ FIXED, PERCENT, hoặc NONE (chỉ chỉnh status/allotment)
                $inventory = RoomInventory::firstOrNew([
                    'room_type_id' => $roomTypeId,
                    'apply_date' => $dateStr
                ]);

                // Xử lý tính toán giá động 
                if ($request->update_type === 'fixed' && !is_null($request->price_value)) {
                    $inventory->price = max(0, (float)$request->price_value);
                } elseif ($request->update_type === 'percent' && !is_null($request->price_value)) {
                    $currentBase = $inventory->exists ? (float)$inventory->price : (float)$roomType->base_price;
                    $calculatedPrice = $currentBase + ($currentBase * ((float)$request->price_value / 100));
                    $inventory->price = max(0, $calculatedPrice);
                } elseif (!$inventory->exists) {
                    $inventory->price = $roomType->base_price;
                }

                if ($request->change_status) {
                    $inventory->is_closed = $request->is_closed ? 1 : 0;
                }

                if ($request->change_allotment && !is_null($request->allotment_value)) {
                    $inventory->available_allotment = max(0, (int)$request->allotment_value);
                } elseif (!$inventory->exists) {
                    $bookedRooms = \App\Models\BookingDetail::where('room_type_id', $roomTypeId)
                        ->whereHas('booking', function ($q) use ($dateStr) {
                            $q->whereIn('status', [1, 2])
                                ->where('check_in', '<=', $dateStr)
                                ->where('check_out', '>', $dateStr);
                        })->sum('rooms_count');

                    $inventory->available_allotment = max(0, $totalPhysicalRooms - $bookedRooms);
                }

                $inventory->save();
            }
        }

        return response()->json(['message' => 'Cập nhật lịch phòng thành công!'], 200);
    }
}
