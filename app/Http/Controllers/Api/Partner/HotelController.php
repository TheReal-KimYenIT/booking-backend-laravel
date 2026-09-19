<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Media;
use App\Models\RoomType;
use App\Models\Booking;
use App\Models\Amenity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HotelController extends Controller
{
    public function show(Request $request)
    {
        $hotelId = $this->getHotelId();

        if (!$hotelId) return response()->json(['message' => 'Bạn chưa tạo hồ sơ khách sạn.', 'hotel' => null], 200);

        $hotel = Hotel::with(['images'])->find($hotelId);
        return response()->json(['message' => 'Lấy chi tiết hồ sơ thành công', 'hotel' => $hotel], 200);
    }

    public function update(Request $request)
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'tax_code' => 'nullable|string|max:50',
            'star_rating' => 'nullable|integer|min:1|max:5',
            'standard_check_in_time' => ['nullable', 'regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'standard_check_out_time' => ['nullable', 'regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
        ]);

        $hotelId = $this->getHotelId();
        $ownerId = $this->getOwnerId();

        $data = [
            'name' => $request->name,
            'description' => $request->description,
            'address' => $request->address,
            'city' => $request->city,
            'tax_code' => $request->tax_code,
            'star_rating' => $request->star_rating,
            'standard_check_in_time' => $request->standard_check_in_time,
            'standard_check_out_time' => $request->standard_check_out_time,
        ];

        if ($hotelId) {
            $hotel = Hotel::find($hotelId);
            $hotel->update($data);
        } else {
            $data['partner_id'] = $ownerId;
            $data['status'] = 0;
            $hotel = Hotel::create($data);
        }

        return response()->json(['message' => 'Cập nhật thông tin chung thành công!', 'hotel' => $hotel], 200);
    }

    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:20480',
            'media' => 'nullable|array',
            'media.*' => 'file|mimes:jpeg,png,jpg,webp|max:20480',
            'business_license_image' => 'nullable|file|mimes:jpeg,png,jpg,webp,pdf|max:20480',
            'is_primary' => 'nullable|boolean'
        ]);

        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Không tìm thấy khách sạn'], 404);

        $hotel = Hotel::find($hotelId);

        // 1. Upload nhiều ảnh khách sạn (media[])
        if ($request->hasFile('media')) {
            $maxOrder = Media::where('model_type', 'Hotel')->where('model_id', $hotelId)->max('sort_order') ?? 0;
            $hasExistingPrimary = Media::where('model_type', 'Hotel')->where('model_id', $hotelId)->where('is_primary', 1)->exists();

            foreach ($request->file('media') as $index => $file) {
                $path = $file->store('hotels', 'public');
                $isPrimary = (!$hasExistingPrimary && $index === 0) ? 1 : 0;

                Media::create([
                    'model_type' => 'Hotel',
                    'model_id'   => $hotelId,
                    'file_url'   => '/storage/' . $path,
                    'is_primary' => $isPrimary,
                    'sort_order' => ++$maxOrder
                ]);
            }
        }

        // 2. Upload ảnh đại diện đơn lẻ (image - backwards compatibility)
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('hotels', 'public');
            Media::create([
                'model_type' => 'Hotel',
                'model_id'   => $hotelId,
                'file_url'   => '/storage/' . $path,
                'is_primary' => $request->is_primary ?? 0,
                'sort_order' => (Media::where('model_type', 'Hotel')->where('model_id', $hotelId)->max('sort_order') ?? 0) + 1
            ]);
        }

        // 3. Upload giấy phép kinh doanh (business_license_image)
        if ($request->hasFile('business_license_image')) {
            $licensePath = $request->file('business_license_image')->store('hotels/licenses', 'public');
            $hotel->update(['business_license_url' => '/storage/' . $licensePath]);
        }

        $hotel->load(['images']);
        return response()->json(['message' => 'Tải ảnh thành công!', 'hotel' => $hotel], 200);
    }

    // Cập nhật thứ tự sắp xếp ảnh (Drag & Drop)
    public function reorderImages(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Không tìm thấy khách sạn'], 404);

        $request->validate([
            'image_ids' => 'required|array',
            'image_ids.*' => 'integer'
        ]);

        foreach ($request->image_ids as $index => $id) {
            Media::where('id', $id)
                ->where('model_type', 'Hotel')
                ->where('model_id', $hotelId)
                ->update(['sort_order' => $index]);
        }

        return response()->json(['message' => 'Đã lưu thứ tự sắp xếp ảnh thành công!'], 200);
    }



    public function getHotelAmenities(Request $request)
    {
        $hotelId = $this->getHotelId();
        $allAmenities = Amenity::where('type', 1)->get();
        $selectedAmenityIds = [];

        if ($hotelId) {
            $selectedAmenityIds = DB::table('hotel_amenity')
                ->where('hotel_id', $hotelId)->pluck('amenity_id')->toArray();
        }
        return response()->json(['all_amenities' => $allAmenities, 'selected_ids' => $selectedAmenityIds], 200);
    }

    public function updateAmenities(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Bạn chưa cập nhật thông tin khách sạn!'], 404);

        $request->validate(['amenity_ids' => 'array']);

        DB::table('hotel_amenity')->where('hotel_id', $hotelId)->delete();

        $insertData = [];
        if (!empty($request->amenity_ids)) {
            foreach ($request->amenity_ids as $a_id) {
                $insertData[] = ['hotel_id' => $hotelId, 'amenity_id' => $a_id];
            }
            DB::table('hotel_amenity')->insert($insertData);
        }
        return response()->json(['message' => 'Cập nhật tiện ích thành công!'], 200);
    }

    public function getStats(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) {
            return response()->json([
                'pending_orders' => 0, 
                'total_room_types' => 0, 
                'total_revenue' => 0,
                'occupancy_rate' => 0,
                'check_ins_today' => 0,
                'cancellation_rate' => 0,
                'revenue_chart' => [],
                'status_chart' => [],
                'room_type_chart' => []
            ], 200);
        }

        \App\Services\BookingCleanupService::autoCleanupIfNeeded();

        $totalRooms = RoomType::where('hotel_id', $hotelId)->count();
        $newBookings = Booking::where('hotel_id', $hotelId)->where('status', 0)->count();
        
        $revenueCompleted = Booking::where('hotel_id', $hotelId)->whereIn('status', [1, 2, 3])->sum('total_amount');
        $revenueCancelled = Booking::where('hotel_id', $hotelId)->where('status', 4)
            ->selectRaw('SUM(deposit_amount - refund_amount) as penalty')
            ->value('penalty') ?? 0;
        $revenue = $revenueCompleted + $revenueCancelled;

        // --- NEW METRICS ---
        // 1. Check-ins today
        $checkInsToday = Booking::where('hotel_id', $hotelId)
            ->whereDate('check_in', \Carbon\Carbon::today())
            ->whereIn('status', [1]) // Đã xác nhận, chờ check-in
            ->count();

        // 1.1 Check-outs today
        $checkOutsToday = Booking::where('hotel_id', $hotelId)
            ->whereDate('check_out', \Carbon\Carbon::today())
            ->whereIn('status', [1, 2]) // Đã xác nhận (nhưng ở 1 đêm) hoặc Đang ở
            ->count();

        // 2. Cancellation Rate
        $totalBookingsThisMonth = Booking::where('hotel_id', $hotelId)
            ->whereMonth('created_at', \Carbon\Carbon::now()->month)
            ->count();
        $cancelledBookingsThisMonth = Booking::where('hotel_id', $hotelId)
            ->whereMonth('created_at', \Carbon\Carbon::now()->month)
            ->where('status', 4)
            ->count();
        $cancellationRate = $totalBookingsThisMonth > 0 
            ? round(($cancelledBookingsThisMonth / $totalBookingsThisMonth) * 100, 1) 
            : 0;

        // 3. Occupancy Rate (Công suất phòng hôm nay)
        $totalPhysicalRooms = \App\Models\Room::whereHas('roomType', function($q) use ($hotelId) {
            $q->where('hotel_id', $hotelId);
        })->count(); // TỔNG Số phòng vật lý của khách sạn (không phân biệt Trống hay Đang ở)

        // Số phòng đang có khách ở hôm nay (dựa vào đơn đang có status = 2)
        $occupiedRoomsToday = \App\Models\BookingDetail::whereHas('booking', function($q) use ($hotelId) {
            $q->where('hotel_id', $hotelId)
              ->whereIn('status', [2]) // Đang ở
              ->whereDate('check_in', '<=', \Carbon\Carbon::today())
              ->whereDate('check_out', '>', \Carbon\Carbon::today());
        })->sum('rooms_count');

        $occupancyRate = $totalPhysicalRooms > 0 
            ? round(($occupiedRoomsToday / $totalPhysicalRooms) * 100, 1) 
            : 0;
            
        // Đảm bảo không vượt quá 100% (do dữ liệu test có thể bị overbooking)
        $occupancyRate = min(100, $occupancyRate);
        // -------------------

        // 1. Revenue Chart (Filtered by period)
        $period = $request->query('period', 'month'); // day, month, year
        $revenueChart = [];
        
        if (in_array($period, ['7days', '14days', '30days', 'day'])) {
            $daysCount = 7;
            if ($period == '14days') $daysCount = 14;
            if ($period == '30days') $daysCount = 30;

            for ($i = $daysCount - 1; $i >= 0; $i--) {
                $date = \Carbon\Carbon::now()->subDays($i);
                $label = $date->format('d/m');
                $start = $date->copy()->startOfDay();
                $end = $date->copy()->endOfDay();
                
                $sumCompleted = Booking::where('hotel_id', $hotelId)
                    ->whereIn('status', [1, 2, 3])
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('total_amount');

                $sumCancelled = Booking::where('hotel_id', $hotelId)
                    ->where('status', 4)
                    ->whereBetween('created_at', [$start, $end])
                    ->selectRaw('SUM(deposit_amount - refund_amount) as penalty')
                    ->value('penalty') ?? 0;

                $revenueChart[] = ['label' => $label, 'revenue' => (float)($sumCompleted + $sumCancelled)];
            }
        } elseif ($period == 'year') {
            for ($i = 4; $i >= 0; $i--) {
                $date = \Carbon\Carbon::now()->subYears($i);
                $label = $date->format('Y');
                $start = $date->copy()->startOfYear();
                $end = $date->copy()->endOfYear();

                $sumCompleted = Booking::where('hotel_id', $hotelId)
                    ->whereIn('status', [1, 2, 3])
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('total_amount');

                $sumCancelled = Booking::where('hotel_id', $hotelId)
                    ->where('status', 4)
                    ->whereBetween('created_at', [$start, $end])
                    ->selectRaw('SUM(deposit_amount - refund_amount) as penalty')
                    ->value('penalty') ?? 0;

                $revenueChart[] = ['label' => $label, 'revenue' => (float)($sumCompleted + $sumCancelled)];
            }
        } else { // month (mặc định 6 tháng qua)
            for ($i = 5; $i >= 0; $i--) {
                $date = \Carbon\Carbon::now()->subMonths($i);
                $label = $date->format('m/Y');
                $start = $date->copy()->startOfMonth();
                $end = $date->copy()->endOfMonth();

                $sumCompleted = Booking::where('hotel_id', $hotelId)
                    ->whereIn('status', [1, 2, 3])
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('total_amount');

                $sumCancelled = Booking::where('hotel_id', $hotelId)
                    ->where('status', 4)
                    ->whereBetween('created_at', [$start, $end])
                    ->selectRaw('SUM(deposit_amount - refund_amount) as penalty')
                    ->value('penalty') ?? 0;

                $revenueChart[] = ['label' => $label, 'revenue' => (float)($sumCompleted + $sumCancelled)];
            }
        }

        // 2. Status Chart
        $statusChart = Booking::where('hotel_id', $hotelId)
            ->where('status', '!=', 0) // Bỏ qua các đơn "Chờ thanh toán" vì nó tồn tại rất ngắn
            ->select('status', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        // 3. Room Type Chart (Doanh thu & Số lượng từng hạng phòng)
        $roomTypeStats = \Illuminate\Support\Facades\DB::table('booking_details')
            ->join('bookings', 'booking_details.booking_id', '=', 'bookings.id')
            ->join('room_types', 'booking_details.room_type_id', '=', 'room_types.id')
            ->where('bookings.hotel_id', $hotelId)
            ->whereIn('bookings.status', [1, 2, 3]) // Chỉ tính đơn thành công
            ->select(
                'room_types.name as room_type_name', 
                \Illuminate\Support\Facades\DB::raw('count(booking_details.id) as count'),
                \Illuminate\Support\Facades\DB::raw('sum(booking_details.subtotal) as revenue')
            )
            ->groupBy('room_types.id', 'room_types.name')
            ->orderByDesc('count')
            ->get();

        $roomTypeChart = $roomTypeStats;
        $mostBookedRoom = $roomTypeStats->first()->room_type_name ?? 'Chưa có';
        $leastBookedRoom = $roomTypeStats->last()->room_type_name ?? 'Chưa có';

        return response()->json([
            'pending_orders' => $newBookings,
            'total_room_types' => $totalRooms,
            'total_revenue' => $revenue,
            'occupancy_rate' => $occupancyRate,
            'check_ins_today' => $checkInsToday,
            'check_outs_today' => $checkOutsToday,
            'cancellation_rate' => $cancellationRate,
            'revenue_chart' => $revenueChart,
            'status_chart' => $statusChart,
            'room_type_chart' => $roomTypeChart,
            'most_booked_room' => $mostBookedRoom,
            'least_booked_room' => $leastBookedRoom
        ], 200);
    }
}
