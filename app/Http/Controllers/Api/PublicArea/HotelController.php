<?php

namespace App\Http\Controllers\Api\PublicArea;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\Amenity;
use App\Models\Room;
use App\Models\BookingDetail;
use App\Models\RoomInventory;
use App\Models\RoomView;
use App\Models\BedType;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class HotelController extends Controller
{
    /**
     * Tính toán giá phòng động và tình trạng phòng khả dụng dựa trên room_inventory và booking_details
     */
    private function calculateRoomAvailabilityAndPrice($roomType, $checkIn, $checkOut, $requestedRooms = 1, $preloadedInventories = null, $preloadedBookedDetails = null, $totalPhysicalRooms = null)
    {
        $basePrice = (float)$roomType->base_price;

        if ($totalPhysicalRooms === null) {
            $totalPhysicalRooms = Room::where('hotel_id', $roomType->hotel_id)
                ->where('room_type_id', $roomType->id)
                ->where('status', '!=', 0)
                ->count();
        }

        // Trường hợp không truyền ngày tìm kiếm: Lấy giá ngày hôm nay từ room_inventory (nếu có tùy chỉnh) hoặc fallback base_price
        if (!$checkIn || !$checkOut) {
            $todayStr = Carbon::today()->format('Y-m-d');
            $todayInv = null;
            if ($preloadedInventories) {
                $todayInv = $preloadedInventories->first(function ($item) use ($todayStr) {
                    $applyDate = $item->apply_date instanceof Carbon ? $item->apply_date->format('Y-m-d') : substr($item->apply_date, 0, 10);
                    return $applyDate === $todayStr;
                });
            } else {
                $todayInv = RoomInventory::where('room_type_id', $roomType->id)->where('apply_date', $todayStr)->first();
            }

            $price = ($todayInv && !is_null($todayInv->price)) ? (float)$todayInv->price : $basePrice;
            $isClosed = $todayInv ? (int)$todayInv->is_closed : 0;
            $avail = ($todayInv && !is_null($todayInv->available_allotment)) ? (int)$todayInv->available_allotment : $totalPhysicalRooms;

            return [
                'is_available' => ($isClosed !== 1 && $avail >= $requestedRooms),
                'available_rooms' => max(0, $avail),
                'avg_daily_price' => $price,
                'stay_total' => $price,
                'daily_breakdown' => [
                    ['date' => $todayStr, 'price' => $price, 'available' => $avail, 'is_closed' => $isClosed]
                ]
            ];
        }

        $startDate = Carbon::parse($checkIn);
        $endDate = Carbon::parse($checkOut);
        if ($endDate->lessThanOrEqualTo($startDate)) {
            $endDate = $startDate->copy()->addDay();
        }
        $nights = $startDate->diffInDays($endDate);
        if ($nights <= 0) $nights = 1;

        if ($totalPhysicalRooms < $requestedRooms) {
            return [
                'is_available' => false,
                'available_rooms' => $totalPhysicalRooms,
                'avg_daily_price' => $basePrice,
                'stay_total' => $basePrice * $nights,
                'daily_breakdown' => []
            ];
        }

        // Tạo danh sách các đêm lưu trú (từ startDate đến ngày trước check-out)
        $period = CarbonPeriod::create($startDate, $endDate->copy()->subDay());
        $isAvailable = true;
        $totalStayPrice = 0;
        $minAvailableAllotment = PHP_INT_MAX;
        $dailyBreakdown = [];

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');

            // Tìm inventory tương ứng ngày này
            $inv = null;
            if ($preloadedInventories) {
                $inv = $preloadedInventories->first(function ($item) use ($dateStr) {
                    $applyDate = $item->apply_date instanceof Carbon ? $item->apply_date->format('Y-m-d') : substr($item->apply_date, 0, 10);
                    return $applyDate === $dateStr;
                });
            } else {
                $inv = RoomInventory::where('room_type_id', $roomType->id)->where('apply_date', $dateStr)->first();
            }

            // 1. Kiểm tra nếu chủ khách sạn đóng bán phòng ngày này
            if ($inv && (int)$inv->is_closed === 1) {
                $isAvailable = false;
                break;
            }

            // 2. Tính số lượng phòng còn trống vào ngày này
            if ($inv && !is_null($inv->available_allotment)) {
                $availableForDate = (int)$inv->available_allotment;
            } else {
                // Tính dựa trên tổng phòng trừ đi số phòng đã được đặt
                if ($preloadedBookedDetails) {
                    $bookedCount = $preloadedBookedDetails->filter(function ($detail) use ($dateStr) {
                        if (!$detail->booking) return false;
                        $cin = $detail->booking->check_in instanceof Carbon ? $detail->booking->check_in->format('Y-m-d') : substr($detail->booking->check_in, 0, 10);
                        $cout = $detail->booking->check_out instanceof Carbon ? $detail->booking->check_out->format('Y-m-d') : substr($detail->booking->check_out, 0, 10);
                        return ($cin <= $dateStr && $cout > $dateStr);
                    })->sum('rooms_count');
                } else {
                    $bookedCount = BookingDetail::where('room_type_id', $roomType->id)
                        ->whereHas('booking', function ($query) use ($dateStr) {
                            $query->whereIn('status', [1, 2])
                                ->where('check_in', '<=', $dateStr)
                                ->where('check_out', '>', $dateStr);
                        })->sum('rooms_count');
                }

                $availableForDate = max(0, $totalPhysicalRooms - $bookedCount);
            }

            // Nếu số phòng trống < số phòng yêu cầu -> không khả dụng
            if ($availableForDate < $requestedRooms) {
                $isAvailable = false;
                break;
            }

            $minAvailableAllotment = min($minAvailableAllotment, $availableForDate);

            // 3. Lấy giá linh động theo ngày (ưu tiên cấu hình từ chủ khách sạn)
            $nightPrice = ($inv && !is_null($inv->price)) ? (float)$inv->price : $basePrice;
            $totalStayPrice += $nightPrice;

            $dailyBreakdown[] = [
                'date' => $dateStr,
                'price' => $nightPrice,
                'available' => $availableForDate,
                'is_closed' => 0
            ];
        }

        if (!$isAvailable) {
            return [
                'is_available' => false,
                'available_rooms' => 0,
                'avg_daily_price' => $basePrice,
                'stay_total' => $basePrice * $nights,
                'daily_breakdown' => $dailyBreakdown
            ];
        }

        $avgDailyPrice = $totalStayPrice / $nights;

        return [
            'is_available' => true,
            'available_rooms' => ($minAvailableAllotment === PHP_INT_MAX ? $totalPhysicalRooms : $minAvailableAllotment),
            'avg_daily_price' => round($avgDailyPrice),
            'stay_total' => round($totalStayPrice),
            'daily_breakdown' => $dailyBreakdown
        ];
    }

    public function search(Request $request)
    {
        $destination = $request->query('destination');
        $hotelName = $request->query('hotelName');
        $checkIn = $request->query('check_in') ?: $request->query('checkIn');
        $checkOut = $request->query('check_out') ?: $request->query('checkOut');

        // Lấy số lượng phòng khách yêu cầu (Mặc định là 1)
        $requestedRooms = (int)$request->query('rooms', 1);
        if ($requestedRooms < 1) $requestedRooms = 1;

        $stars = $request->query('stars');
        $hotelAmenities = $request->query('hotel_amenities');
        $roomAmenities = $request->query('room_amenities');
        $priceMin = $request->query('price_min');
        $priceMax = $request->query('price_max');
        $sortBy = $request->query('sort_by', 'popular');
        $hasBreakfast = $request->query('has_breakfast');
        $freeCancellation = $request->query('free_cancellation');
        $bedTypes = $request->query('bed_types');
        $roomViews = $request->query('room_views');

        if ($stars && !is_array($stars)) $stars = explode(',', $stars);
        if ($hotelAmenities && !is_array($hotelAmenities)) $hotelAmenities = explode(',', $hotelAmenities);
        if ($roomAmenities && !is_array($roomAmenities)) $roomAmenities = explode(',', $roomAmenities);
        if ($bedTypes && !is_array($bedTypes)) $bedTypes = explode(',', $bedTypes);
        if ($roomViews && !is_array($roomViews)) $roomViews = explode(',', $roomViews);

        $query = Hotel::with([
            'images',
            'roomTypes' => function ($q) {
                $q->where('status', 1)->with('amenities');
            },
            'amenities'
        ])->where('status', 1);

        if ($hotelName && trim($hotelName) !== '') {
            $query->where('name', 'LIKE', '%' . trim($hotelName) . '%');
        }

        if ($destination && trim($destination) !== '') {
            $query->where(function ($q) use ($destination) {
                $q->where('city', 'LIKE', '%' . trim($destination) . '%')
                  ->orWhere('address', 'LIKE', '%' . trim($destination) . '%')
                  ->orWhere('name', 'LIKE', '%' . trim($destination) . '%');
            });
        }

        if (!empty($stars)) {
            $query->whereIn('star_rating', $stars);
        }

        if (!empty($hotelAmenities)) {
            $query->whereHas('amenities', function ($q) use ($hotelAmenities) {
                $q->whereIn('amenities.id', $hotelAmenities);
            });
        }

        if (!empty($roomAmenities)) {
            $query->whereHas('roomTypes', function ($q) use ($roomAmenities) {
                $q->where('status', 1)->whereHas('amenities', function ($qa) use ($roomAmenities) {
                    $qa->whereIn('amenities.id', $roomAmenities);
                });
            });
        }

        if ($hasBreakfast) {
            $query->whereHas('roomTypes', function ($q) {
                $q->where('status', 1)->where('has_breakfast', 1);
            });
        }

        if ($freeCancellation) {
            $query->whereHas('roomTypes', function ($q) {
                $q->where('status', 1)->where('free_cancel_hours', '>', 0);
            });
        }

        if (!empty($bedTypes)) {
            $query->whereHas('roomTypes', function ($q) use ($bedTypes) {
                $q->where('status', 1)->whereIn('bed_type_id', $bedTypes);
            });
        }

        if (!empty($roomViews)) {
            $query->whereHas('roomTypes', function ($q) use ($roomViews) {
                $q->where('status', 1)->whereIn('view_id', $roomViews);
            });
        }

        $hotels = $query->get();

        if ($hotels->isEmpty()) {
            return response()->json([
                'message' => 'Lấy danh sách thành công',
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => (int)$request->query('per_page', 9),
                    'total' => 0
                ]
            ], 200);
        }

        // Tối ưu hóa truy vấn: Tải trước toàn bộ phòng vật lý, lịch RoomInventory và Bookings để tránh N+1
        $hotelIds = $hotels->pluck('id');
        $allRoomTypes = RoomType::whereIn('hotel_id', $hotelIds)->where('status', 1)->get();
        $roomTypeIds = $allRoomTypes->pluck('id');

        $physicalRoomsCounts = Room::whereIn('room_type_id', $roomTypeIds)
            ->where('status', '!=', 0)
            ->groupBy('room_type_id')
            ->selectRaw('room_type_id, count(*) as count')
            ->pluck('count', 'room_type_id');

        $startDateStr = $checkIn ? Carbon::parse($checkIn)->format('Y-m-d') : Carbon::today()->format('Y-m-d');
        $endDateStr = $checkOut ? Carbon::parse($checkOut)->format('Y-m-d') : Carbon::tomorrow()->format('Y-m-d');

        $inventories = RoomInventory::whereIn('room_type_id', $roomTypeIds)
            ->whereBetween('apply_date', [$startDateStr, $endDateStr])
            ->get()
            ->groupBy('room_type_id');

        $bookedDetails = BookingDetail::whereIn('room_type_id', $roomTypeIds)
            ->whereHas('booking', function ($q) use ($startDateStr, $endDateStr) {
                $q->whereIn('status', [1, 2])
                    ->where('check_in', '<', $endDateStr)
                    ->where('check_out', '>', $startDateStr);
            })
            ->with('booking:id,status,check_in,check_out')
            ->get()
            ->groupBy('room_type_id');

        $filteredHotels = collect();

        foreach ($hotels as $hotel) {
            $hasAvailableRoom = false;
            $minPrice = PHP_INT_MAX;
            $hotelRoomTypes = $hotel->roomTypes;

            if ($hotelRoomTypes && $hotelRoomTypes->count() > 0) {
                // Gán cờ đặc điểm chính sách nổi bật cho khách sạn
                $hotel->has_breakfast = $hotelRoomTypes->contains('has_breakfast', 1);
                $hotel->free_cancellation = $hotelRoomTypes->contains(function ($r) {
                    return (int)$r->free_cancel_hours > 0;
                });

                foreach ($hotelRoomTypes as $roomType) {
                    if ($roomType->status != 1) continue;

                    // Lọc loại phòng theo tiện ích phòng nếu có yêu cầu
                    if (!empty($roomAmenities)) {
                        $typeAmenityIds = $roomType->amenities->pluck('id')->map(fn($id) => (string)$id)->toArray();
                        $hasAllRoomAmenities = true;
                        foreach ($roomAmenities as $reqAmenityId) {
                            if (!in_array((string)$reqAmenityId, $typeAmenityIds)) {
                                $hasAllRoomAmenities = false;
                                break;
                            }
                        }
                        if (!$hasAllRoomAmenities) continue;
                    }

                    if ($hasBreakfast && (int)$roomType->has_breakfast !== 1) {
                        continue;
                    }

                    if ($freeCancellation && ((int)$roomType->free_cancel_hours <= 0 || is_null($roomType->free_cancel_hours))) {
                        continue;
                    }

                    if (!empty($bedTypes) && !in_array((string)$roomType->bed_type_id, array_map('strval', $bedTypes))) {
                        continue;
                    }

                    if (!empty($roomViews) && !in_array((string)$roomType->view_id, array_map('strval', $roomViews))) {
                        continue;
                    }

                    $typeInventories = $inventories->get($roomType->id, collect());
                    $typeBookedDetails = $bookedDetails->get($roomType->id, collect());
                    $totalPhysical = $physicalRoomsCounts[$roomType->id] ?? 0;

                    $calc = $this->calculateRoomAvailabilityAndPrice(
                        $roomType, 
                        $checkIn, 
                        $checkOut, 
                        $requestedRooms, 
                        $typeInventories, 
                        $typeBookedDetails, 
                        $totalPhysical
                    );

                    if ($calc['is_available']) {
                        $hasAvailableRoom = true;
                        if ($calc['avg_daily_price'] < $minPrice) {
                            $minPrice = $calc['avg_daily_price'];
                        }
                    }
                }

                // Nếu có tìm theo ngày và không có phòng nào còn trống hoặc mở bán -> bỏ qua khách sạn này
                if ($checkIn && $checkOut && !$hasAvailableRoom) {
                    continue;
                }

                // Nếu không lọc theo ngày mà không còn phòng khả dụng -> lấy giá gốc nhỏ nhất danh nghĩa
                if (!$hasAvailableRoom) {
                    $minPrice = $hotelRoomTypes->min('base_price');
                }

                // Lọc theo khoảng giá tối thiểu và tối đa (dựa trên giá động thực tế)
                if ($priceMin && $minPrice < (float)$priceMin) {
                    continue;
                }
                if ($priceMax && $minPrice > (float)$priceMax) {
                    continue;
                }

                $hotel->min_price = ($minPrice === PHP_INT_MAX) ? null : $minPrice;

                // Gộp tiện ích
                if ($hotel->amenities->count() === 0) {
                    $allAmenities = collect();
                    foreach ($hotelRoomTypes as $room) {
                        $allAmenities = $allAmenities->merge($room->amenities);
                    }
                    $hotel->amenities = $allAmenities->unique('id')->values();
                }
            } else {
                if ($priceMin || $priceMax || ($checkIn && $checkOut) || $hasBreakfast || $freeCancellation || !empty($bedTypes) || !empty($roomViews)) continue;
                $hotel->min_price = null;
                $hotel->has_breakfast = false;
                $hotel->free_cancellation = false;
            }

            unset($hotel->roomTypes);
            $filteredHotels->push($hotel);
        }

        // Xử lý sắp xếp linh hoạt
        if ($sortBy === 'price_asc') {
            $filteredHotels = $filteredHotels->sortBy('min_price')->values();
        } elseif ($sortBy === 'price_desc') {
            $filteredHotels = $filteredHotels->sortByDesc('min_price')->values();
        } elseif ($sortBy === 'rating_desc') {
            $filteredHotels = $filteredHotels->sortByDesc(function ($h) {
                return (float)($h->average_rating ?? 0);
            })->values();
        } elseif ($sortBy === 'popular') {
            $filteredHotels = $filteredHotels->sortByDesc(function ($h) {
                return ((int)$h->star_rating * 100) + (float)($h->average_rating ?? 0);
            })->values();
        }

        // Xử lý phân trang chuẩn xác
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 9);
        $total = $filteredHotels->count();
        $lastPage = max(1, (int)ceil($total / $perPage));
        $currentPage = min(max(1, $page), $lastPage);

        $paginatedHotels = ($request->has('page') || $request->has('per_page'))
            ? $filteredHotels->forPage($currentPage, $perPage)->values()
            : $filteredHotels->values();

        return response()->json([
            'message' => 'Lấy danh sách thành công',
            'data' => $paginatedHotels,
            'meta' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total
            ]
        ], 200);
    }

    public function getFiltersData()
    {
        $hotelAmenities = Amenity::where('type', 1)->get();
        $roomAmenities = Amenity::where('type', 2)->get();
        $bedTypes = BedType::where('status', 1)->get(['id', 'name']);
        $roomViews = RoomView::where('status', 1)->get(['id', 'name']);

        return response()->json([
            'message' => 'Lấy danh sách danh mục bộ lọc thành công',
            'data' => [
                'hotel_amenities' => $hotelAmenities,
                'room_amenities' => $roomAmenities,
                'bed_types' => $bedTypes,
                'room_views' => $roomViews
            ]
        ], 200);
    }

    public function getDetail(Request $request, int $id)
    {
        $checkIn = $request->query('checkIn') ?: $request->query('check_in');
        $checkOut = $request->query('checkOut') ?: $request->query('check_out');

        // Lấy số lượng phòng yêu cầu (Từ URL, mặc định là 1)
        $requestedRooms = (int)$request->query('rooms', 1);
        if ($requestedRooms < 1) $requestedRooms = 1;

        $hotel = Hotel::with(['images', 'amenities'])->find($id);
        if (!$hotel) {
            return response()->json(['message' => 'Không tìm thấy khách sạn'], 404);
        }

        $roomTypes = RoomType::with(['media', 'amenities', 'roomView', 'bedTypeDetail'])
            ->where('hotel_id', $id)
            ->where('status', 1)
            ->get();

        $roomTypeIds = $roomTypes->pluck('id');

        $startDateStr = $checkIn ? Carbon::parse($checkIn)->format('Y-m-d') : Carbon::today()->format('Y-m-d');
        $endDateStr = $checkOut ? Carbon::parse($checkOut)->format('Y-m-d') : Carbon::tomorrow()->format('Y-m-d');

        $physicalRoomsCounts = Room::whereIn('room_type_id', $roomTypeIds)
            ->where('status', '!=', 0)
            ->groupBy('room_type_id')
            ->selectRaw('room_type_id, count(*) as count')
            ->pluck('count', 'room_type_id');

        $inventories = RoomInventory::whereIn('room_type_id', $roomTypeIds)
            ->whereBetween('apply_date', [$startDateStr, $endDateStr])
            ->get()
            ->groupBy('room_type_id');

        $bookedDetails = BookingDetail::whereIn('room_type_id', $roomTypeIds)
            ->whereHas('booking', function ($q) use ($startDateStr, $endDateStr) {
                $q->whereIn('status', [1, 2])
                    ->where('check_in', '<', $endDateStr)
                    ->where('check_out', '>', $startDateStr);
            })
            ->with('booking:id,status,check_in,check_out')
            ->get()
            ->groupBy('room_type_id');

        foreach ($roomTypes as $roomType) {
            $typeInventories = $inventories->get($roomType->id, collect());
            $typeBookedDetails = $bookedDetails->get($roomType->id, collect());
            $totalPhysical = $physicalRoomsCounts[$roomType->id] ?? 0;

            $calc = $this->calculateRoomAvailabilityAndPrice(
                $roomType, 
                $checkIn, 
                $checkOut, 
                $requestedRooms, 
                $typeInventories, 
                $typeBookedDetails, 
                $totalPhysical
            );

            $roomType->is_available = $calc['is_available'];
            $roomType->available_rooms = $calc['available_rooms'];
            $roomType->daily_price = $calc['avg_daily_price'];
            $roomType->stay_total = $calc['stay_total'];
            $roomType->daily_rates = $calc['daily_breakdown'];
        }

        if ($checkIn && $checkOut) {
            // Lọc các phòng mở và có đủ số lượng theo yêu cầu
            $roomTypes = $roomTypes->filter(function ($room) use ($requestedRooms) {
                return $room->is_available && $room->available_rooms >= $requestedRooms;
            })->values();
        }

        $hotel->room_types = $roomTypes;
        $hotel->min_price = $roomTypes->isNotEmpty()
            ? ($roomTypes->min('daily_price') ?: $roomTypes->min('base_price'))
            : null;

        return response()->json(['message' => 'Lấy chi tiết thành công', 'data' => $hotel], 200);
    }

    public function getRoomMasterData()
    {
        $views = RoomView::where('status', 1)->get(['id', 'name']);
        $beds = BedType::where('status', 1)->get(['id', 'name']);

        return response()->json([
            'message' => 'Lấy danh mục thành công',
            'data' => [
                'room_views' => $views,
                'bed_types' => $beds
            ]
        ], 200);
    }

    public function getDestinationsCount(Request $request)
    {
        $destinations = $request->query('destinations', []);
        
        if (empty($destinations)) {
            $destinations = ['Đà Lạt', 'Nha Trang', 'Vũng Tàu', 'Đà Nẵng', 'Hồ Chí Minh', 'Hà Nội'];
        } else if (!is_array($destinations)) {
            $destinations = explode(',', $destinations);
        }

        $counts = [];
        foreach ($destinations as $dest) {
            $count = \App\Models\Hotel::where('status', 1)->where(function ($q) use ($dest) {
                $q->where('city', 'like', "%{$dest}%")
                  ->orWhere('address', 'like', "%{$dest}%");
            })->count();
            
            $counts[$dest] = $count;
        }

        return response()->json([
            'status' => 'success',
            'data' => $counts
        ]);
    }

}
