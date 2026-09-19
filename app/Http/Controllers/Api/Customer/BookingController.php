<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\RoomType;
use App\Models\BookingDetail;
use App\Models\RoomInventory;
use App\Models\Payment;
use App\Models\Service;
use App\Models\BookingService;
use App\Models\Promotion;
use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BookingController extends Controller
{
    // Tạo mã đơn đặt phòng tự động để dễ quản lý.
    private function generateBookingCode($prefix = 'BK', $customSuffix = null)
    {
        $datePart = now()->format('Ymd');
        $suffix = $customSuffix ? strtoupper($customSuffix) : strtoupper(Str::random(6));
        return "{$prefix}{$datePart}-{$suffix}";
    }

    // Điều hướng sang hàm tạo booking chính.
    public function store(Request $request)
    {
        return $this->createBooking($request);
    }

    // Tạo đơn đặt phòng mới cho khách hàng.
    public function createBooking(Request $request)
    {
        $request->validate([
            'hotel_id' => 'required|exists:hotels,id',
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
            'guest_name' => 'required|string|max:255',
            'guest_phone' => 'required|string|max:20',
            'guest_email' => 'required|email',
            'rooms_count' => 'required|integer|min:1',
            'note' => 'nullable|string',
            'services' => 'nullable|array',
            'services.*.id' => 'required_with:services|integer|exists:services,id',
            'services.*.quantity' => 'required_with:services|integer|min:1',
            'global_promotion_code' => 'nullable|string',
            'hotel_promotion_code' => 'nullable|string',
            'is_online_payment' => 'nullable|boolean' // Flag nhận biết để gắn mã SB hay BK
        ]);

        $customerId = auth('customer')->id();

        // Tự động dọn dẹp các đơn hết hạn thanh toán để giải phóng kho phòng trước khi khách đặt mới
        \App\Services\BookingCleanupService::autoCleanupIfNeeded();

        // Kiểm tra xem khách hàng có bị chặn khỏi khách sạn này không.
        $isBlocked = DB::table('hotel_blacklists')
            ->where('hotel_id', $request->hotel_id)
            ->where('customer_id', $customerId)
            ->first();

        if ($isBlocked) {
            return response()->json([
                'message' => 'Rất tiếc, bạn không thể đặt phòng tại khách sạn này do vi phạm chính sách của khách sạn trước đó. Vui lòng liên hệ trực tiếp với khách sạn để được hỗ trợ.'
            ], 403);
        }

        try {
            $result = DB::transaction(function () use ($request, $customerId) {
                $roomType = RoomType::find($request->room_type_id);
                if (!$roomType) throw new \Exception('Không tìm thấy loại phòng hợp lệ.');

                $uiSettings = DB::table('system_settings')->pluck('setting_value', 'setting_key');
                $systemVatRate = isset($uiSettings['vat_rate']) ? (float)$uiSettings['vat_rate'] : 10;
                $defaultCommission = isset($uiSettings['default_commission_rate']) ? (float)$uiSettings['default_commission_rate'] : 15;

                $hotel = Hotel::find($request->hotel_id);
                $hotelCommissionRate = $hotel->commission_rate ?? $defaultCommission;

                $checkIn = Carbon::parse($request->check_in);
                $checkOut = Carbon::parse($request->check_out);
                $nights = $checkIn->diffInDays($checkOut);
                if ($nights <= 0) $nights = 1;

                $subtotal = 0;
                $currentDate = $checkIn->copy();

                // Tính tổng tiền phòng và KIỂM TRA SỐ LƯỢNG PHÒNG TRỐNG theo từng ngày (Cập nhật Locking).
                for ($i = 0; $i < $nights; $i++) {
                    $dateStr = $currentDate->format('Y-m-d');

                    // Khóa dòng dữ liệu kho phòng của ngày hôm đó
                    $inventory = RoomInventory::where('room_type_id', $roomType->id)
                        ->where('apply_date', $dateStr)
                        ->lockForUpdate()
                        ->first();

                    // FIX BUG: Tự động tạo kho phòng nếu dữ liệu của ngày này chưa tồn tại
                    if (!$inventory) {
                        $totalPhysicalRooms = $roomType->rooms()->count();
                        
                        $bookedRooms = \App\Models\BookingDetail::where('room_type_id', $roomType->id)
                            ->whereHas('booking', function ($q) use ($dateStr) {
                                $q->whereIn('status', [1, 2])
                                    ->where('check_in', '<=', $dateStr)
                                    ->where('check_out', '>', $dateStr);
                            })->sum('rooms_count');

                        $inventory = RoomInventory::create([
                            'room_type_id' => $roomType->id,
                            'apply_date' => $dateStr,
                            // Tự động lấy giá gốc của loại phòng
                            'price' => $roomType->base_price ?? 0,
                            // Lấy chính xác số phòng vật lý hiện có trừ đi số lượng đã đặt
                            'available_allotment' => max(0, $totalPhysicalRooms - $bookedRooms),
                            'is_closed' => 0
                        ]);
                    }

                    // Kiểm tra xem phòng có bị chủ động tạm ngưng nhận khách không
                    if ((int)$inventory->is_closed === 1) {
                        throw new \Exception("Rất tiếc, loại phòng này đã dừng nhận khách vào ngày " . $currentDate->format('d/m/Y'));
                    }

                    // Kiểm tra số lượng phòng còn lại CÓ ĐỦ cho yêu cầu của khách không
                    if ($inventory->available_allotment < $request->rooms_count) {
                        throw new \Exception("Rất tiếc, chỉ còn " . $inventory->available_allotment . " phòng trống vào ngày " . $currentDate->format('d/m/Y'));
                    }

                    // Trừ số lượng phòng và lưu lại vào Database
                    $inventory->available_allotment -= $request->rooms_count;
                    $inventory->save();

                    // Lấy giá tiền của ngày hôm đó để cộng vào tổng tiền
                    $dailyPrice = $inventory->price;

                    // Cộng dồn tiền phòng
                    $subtotal += $dailyPrice;
                    $currentDate->addDay();
                }

                $subtotal = $subtotal * $request->rooms_count;

                // Tính tổng tiền dịch vụ nếu khách chọn thêm.
                $servicesTotal = 0;
                $requestedServices = [];
                if ($request->has('services') && count($request->services) > 0) {
                    $requestedServices = Service::whereIn('id', array_column($request->services, 'id'))->get();
                    foreach ($requestedServices as $srv) {
                        $qty = collect($request->services)->firstWhere('id', $srv->id)['quantity'] ?? 1;
                        $servicesTotal += ($srv->price * $qty);
                    }
                }

                // Xử lý các mã khuyến mãi nếu có.
                $globalDiscount = 0;
                $hotelDiscount = 0;
                $globalPromoId = null;
                $hotelPromoId = null;

                $now = \Carbon\Carbon::now();

                if ($request->filled('global_promotion_code')) {
                    $promo = Promotion::where('code', $request->global_promotion_code)
                        ->whereNull('hotel_id')->lockForUpdate()->first();

                    if ($promo && $promo->status == 1 && $now >= $promo->start_date && $now <= $promo->end_date && $promo->used_count < ($promo->usage_limit ?? 999999999)) {
                        $usedCount = 0;
                        if ($promo->usage_limit_per_user !== null) {
                            $usedCount = \App\Models\Booking::where('customer_id', $customerId)
                                ->where(function($q) use ($promo) {
                                    $q->where('promotion_id', $promo->id)->orWhere('hotel_promotion_id', $promo->id);
                                })->count();
                        }
                        
                        if ($promo->usage_limit_per_user === null || $usedCount < $promo->usage_limit_per_user) {
                            if ($promo->min_booking_value == 0 || $subtotal >= $promo->min_booking_value) {
                                $globalPromoId = $promo->id;
                                if ($promo->discount_type == 1) {
                                    $globalDiscount = $subtotal * ($promo->discount_value / 100);
                                    if ($promo->max_discount_amount) $globalDiscount = min($globalDiscount, $promo->max_discount_amount);
                                } else {
                                    $globalDiscount = $promo->discount_value;
                                }
                                $globalDiscount = min($globalDiscount, $subtotal);
                                $promo->increment('used_count');
                            }
                        }
                    }
                }

                $subtotalAfterGlobal = $subtotal - $globalDiscount;

                if ($request->filled('hotel_promotion_code')) {
                    $promo = Promotion::where('code', $request->hotel_promotion_code)
                        ->where('hotel_id', $request->hotel_id)->lockForUpdate()->first();

                    if ($promo && $promo->status == 1 && $now >= $promo->start_date && $now <= $promo->end_date && $promo->used_count < ($promo->usage_limit ?? 999999999)) {
                        $usedCount = 0;
                        if ($promo->usage_limit_per_user !== null) {
                            $usedCount = \App\Models\Booking::where('customer_id', $customerId)
                                ->where(function($q) use ($promo) {
                                    $q->where('promotion_id', $promo->id)->orWhere('hotel_promotion_id', $promo->id);
                                })->count();
                        }

                        if ($promo->usage_limit_per_user === null || $usedCount < $promo->usage_limit_per_user) {
                            if ($promo->min_booking_value == 0 || $subtotalAfterGlobal >= $promo->min_booking_value) {
                                $hotelPromoId = $promo->id;
                                if ($promo->discount_type == 1) {
                                    $hotelDiscount = $subtotalAfterGlobal * ($promo->discount_value / 100);
                                    if ($promo->max_discount_amount) $hotelDiscount = min($hotelDiscount, $promo->max_discount_amount);
                                } else {
                                    $hotelDiscount = $promo->discount_value;
                                }
                                $hotelDiscount = min($hotelDiscount, $subtotalAfterGlobal);
                                $promo->increment('used_count');
                            }
                        }
                    }
                }

                $totalDiscount = $globalDiscount + $hotelDiscount;

                // Tính thuế VAT trên tổng tiền sau khi trừ giảm giá.
                $taxableAmount = $subtotal + $servicesTotal - $totalDiscount;
                $tax = max(0, $taxableAmount * ($systemVatRate / 100));
                $totalAmount = $taxableAmount + $tax;
                $platformFee = $totalAmount * ($hotelCommissionRate / 100);
                $depositAmount = $totalAmount / 2;

                // Sinh mã đơn kiểu online (bắt buộc cọc)
                $prefix = 'SB';
                $bookingCode = $this->generateBookingCode($prefix);

                // Lưu thông tin đơn đặt phòng và các giá trị tài chính vào DB.
                $booking = Booking::create([
                    'booking_code' => $bookingCode,
                    'customer_id' => $customerId,
                    'hotel_id' => $request->hotel_id,
                    'promotion_id' => $globalPromoId,
                    'hotel_promotion_id' => $hotelPromoId,
                    'guest_name' => $request->guest_name,
                    'guest_phone' => $request->guest_phone,
                    'guest_email' => $request->guest_email,
                    'note' => $request->note,
                    'check_in' => $request->check_in,
                    'check_out' => $request->check_out,
                    'total_amount' => $totalAmount,
                    'deposit_amount' => $depositAmount,
                    'discount_amount' => $totalDiscount,
                    'vat_amount' => $tax,
                    'vat_rate' => $systemVatRate,
                    'commission_rate' => $hotelCommissionRate,
                    'platform_fee' => $platformFee,
                    'free_cancel_hours' => $roomType->free_cancel_hours,
                    'partial_refund_hours' => $roomType->partial_refund_hours,
                    'partial_refund_percent' => $roomType->partial_refund_percent,
                    'status' => 0,
                    'payment_status' => 0,
                ]);

                if ($totalAmount <= 0) {
                    Payment::create([
                        'booking_id' => $booking->id,
                        'amount' => 0,
                        'payment_method' => 4,
                        'payment_status' => 1,
                        'created_at' => now(),
                    ]);

                    $booking->update([
                        'status' => 1,
                        'payment_status' => 1
                    ]);
                }

                BookingDetail::create([
                    'booking_id' => $booking->id,
                    'room_type_id' => $request->room_type_id,
                    'rooms_count' => $request->rooms_count,
                    'subtotal' => $subtotal
                ]);

                if (count($requestedServices) > 0) {
                    foreach ($requestedServices as $srv) {
                        $qty = collect($request->services)->firstWhere('id', $srv->id)['quantity'] ?? 1;
                        BookingService::create([
                            'booking_id' => $booking->id,
                            'service_id' => $srv->id,
                            'quantity' => $qty,
                            'price_at_booking' => $srv->price,
                            'created_at' => now()
                        ]);
                    }
                }

                return $booking;
            });

            return response()->json([
                'message' => 'Đặt phòng thành công.',
                'booking_code' => $result->booking_code,
                'booking_id' => $result->id,
                'total_amount' => $result->total_amount
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Lỗi khi đặt phòng: ' . $e->getMessage() . ' - Line: ' . $e->getLine() . ' in ' . $e->getFile());
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    // Lấy danh sách các đơn đặt phòng của khách hàng hiện tại.
    public function myBookings(Request $request)
    {
        // Tự động dọn dẹp các đơn chưa thanh toán quá hạn để danh sách hiển thị chuẩn
        \App\Services\BookingCleanupService::autoCleanupIfNeeded();

        $bookings = Booking::with([
            'hotel.images',
            'details.roomType.media',
            'bookingServices.service',
            'surcharges.category',
            'supply_incidents.supply',
            'review.images',
            'promotion',
            'hotelPromotion'
        ])
            ->where('customer_id', auth('customer')->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'message' => 'Lấy danh sách đơn hàng thành công',
            'data' => $bookings
        ], 200);
    }

    // Hủy đơn đặt phòng của chính khách hàng đó.
    public function cancelMyBooking(Request $request, int $id)
    {
        $customerId = auth('customer')->id();

        // Chuyển sang dùng Eloquent Model Booking thay vì DB::table()
        $booking = Booking::where('id', $id)->where('customer_id', $customerId)->first();

        if (!$booking) {
            return response()->json(['message' => 'Không tìm thấy đơn đặt phòng!'], 404);
        }

        if (!in_array($booking->status, [0, 1])) {
            return response()->json(['message' => 'Không thể hủy đơn hàng ở trạng thái này!'], 403);
        }

        // NẾU ĐƠN CHƯA THANH TOÁN (status = 0): Khách chủ động hủy giữ chỗ
        // -> Trả lại kho phòng, trả lại voucher và XÓA HOÀN TOÀN KHỎI DATABASE để người khác đặt ngay!
        if ($booking->status == 0 && $booking->payment_status == 0) {
            DB::transaction(function () use ($booking) {
                // 1. Trả lại kho phòng
                $checkInDate = Carbon::parse($booking->check_in);
                $checkOutDate = Carbon::parse($booking->check_out);
                $bookingDetails = BookingDetail::where('booking_id', $booking->id)->get();

                foreach ($bookingDetails as $detail) {
                    for ($currentDate = $checkInDate->copy(); $currentDate->lt($checkOutDate); $currentDate->addDay()) {
                        $dateStr = $currentDate->format('Y-m-d');
                        $inventory = RoomInventory::where('room_type_id', $detail->room_type_id)
                            ->where('apply_date', $dateStr)
                            ->first();
                        if ($inventory) {
                            $inventory->increment('available_allotment', $detail->rooms_count);
                        }
                    }
                }

                // 2. Trả lại mã khuyến mãi
                if ($booking->promotion_id) {
                    Promotion::where('id', $booking->promotion_id)->where('used_count', '>', 0)->decrement('used_count');
                }
                if ($booking->hotel_promotion_id) {
                    Promotion::where('id', $booking->hotel_promotion_id)->where('used_count', '>', 0)->decrement('used_count');
                }

                // 3. Xóa hoàn toàn khỏi database
                $booking->delete();
            });

            return response()->json([
                'message' => 'Hủy giữ chỗ thành công! Phòng đã được giải phóng cho khách khác đặt ngay lập tức.',
                'deleted' => true
            ], 200);
        }

        $now = Carbon::now();
        // Cố định mốc 14:00 giờ nhận phòng của ngày Check-in
        $checkInDateTime = Carbon::parse($booking->check_in)->setHour(14)->setMinute(0)->setSecond(0);

        // Dùng số phút để tính ra giờ chính xác tuyệt đối (tránh lỗi làm tròn của Carbon)
        $minutesDifference = $now->diffInMinutes($checkInDateTime, false);
        $hoursDifference = $minutesDifference / 60;

        if ($hoursDifference <= 0) {
            return response()->json(['message' => 'Đã qua giờ nhận phòng, không thể hủy!'], 403);
        }

        //  Dùng Eloquent để truy vấn bảng Payment
        $payment = Payment::where('booking_id', $booking->id)->first();
        $isPrepaid = ($payment && $payment->payment_method == 4 && $payment->payment_status == 1);

        $refundAmount = 0;
        $needsRefund = false;
        $refundMessage = '';

        $freeHours = $booking->free_cancel_hours ?? 48;
        $partialHours = $booking->partial_refund_hours ?? 0;
        $partialPercent = $booking->partial_refund_percent ?? 0;

        // THUẬT TOÁN TÍNH TIỀN HỦY VÀ HOA HỒNG (CẬP NHẬT MỚI)
        if ($hoursDifference >= $freeHours) {
            $refundAmount = $isPrepaid ? $payment->amount : 0;
            $refundMessage = $isPrepaid ? 'Hủy phòng thành công. Bạn được hoàn 100% tiền cọc.' : 'Hủy phòng thành công!';
        } elseif ($hoursDifference >= $partialHours && $partialPercent > 0) {
            // Khách được hoàn lại $partialPercent CỦA SỐ TIỀN ĐÃ CỌC.
            $refundAmount = $isPrepaid ? ($payment->amount * ($partialPercent / 100)) : 0;
            $refundMessage = $isPrepaid ? "Hủy phòng thành công. Bạn được hoàn {$partialPercent}% tiền cọc theo chính sách." : 'Hủy phòng thành công!';
        } else {
            // Không được hoàn tiền cọc
            $refundAmount = 0;
            $refundMessage = $isPrepaid ? 'Hủy phòng thành công. Bạn hủy sát giờ nhận phòng nên không được hoàn tiền theo chính sách.' : 'Hủy phòng thành công!';
        }

        // TÍNH LẠI HOA HỒNG CHO ADMIN (Nếu khách sạn giữ lại tiền phạt, nền tảng vẫn được chia % hoa hồng)
        $newPlatformFee = 0;
        if ($isPrepaid) {
            $penaltyAmount = $payment->amount - $refundAmount; // Tiền khách sạn thực lãnh sau khi hủy
            if ($penaltyAmount > 0) {
                $newPlatformFee = $penaltyAmount * ($booking->commission_rate / 100);
            }
        }

        if ($refundAmount > 0) {
            $needsRefund = true;
            $request->validate([
                'refund_bank' => 'required|string',
                'refund_account' => 'required|string',
                'refund_account_name' => 'required|string',
            ]);
            if ($payment) {
                $payment->update(['payment_status' => 2]);
            }
        }

        $booking->update([
            'status' => 4,
            'cancellation_reason' => $request->cancellation_reason,
            'refund_status' => $needsRefund ? 1 : 0, // 1: Chờ hoàn tiền thủ công
            'payment_status' => $needsRefund ? 2 : $booking->payment_status, // 2: Đã hoàn
            'refund_amount' => $refundAmount,
            'platform_fee' => $newPlatformFee, // Cập nhật phí hoa hồng mới
            'refund_bank' => $needsRefund ? $request->refund_bank : null,
            'refund_account' => $needsRefund ? $request->refund_account : null,
            'refund_account_name' => $needsRefund ? $request->refund_account_name : null,
        ]);

        // HOÀN TRẢ LẠI KHO PHÒNG VÀO DATABASE
        $checkInDate = \Carbon\Carbon::parse($booking->check_in);
        $checkOutDate = \Carbon\Carbon::parse($booking->check_out);
        $bookingDetails = \App\Models\BookingDetail::where('booking_id', $booking->id)->get();

        foreach ($bookingDetails as $detail) {
            for ($currentDate = $checkInDate->copy(); $currentDate->lt($checkOutDate); $currentDate->addDay()) {
                $dateStr = $currentDate->format('Y-m-d');
                $inventory = \App\Models\RoomInventory::where('room_type_id', $detail->room_type_id)
                    ->where('apply_date', $dateStr)
                    ->first();
                if ($inventory) {
                    // FIX BUG: Sử dụng hàm increment nguyên tử của DB để tránh lỗi Race Condition khi nhiều người hủy cùng lúc
                    $inventory->increment('available_allotment', $detail->rooms_count);
                }
            }
        }
        // HOÀN TRẢ LẠI MÃ KHUYẾN MÃI VÀO DATABASE
        if ($booking->promotion_id) {
            \App\Models\Promotion::where('id', $booking->promotion_id)->where('used_count', '>', 0)->decrement('used_count');
        }
        if ($booking->hotel_promotion_id) {
            \App\Models\Promotion::where('id', $booking->hotel_promotion_id)->where('used_count', '>', 0)->decrement('used_count');
        }

        // GIẢI PHÓNG PHÒNG VẬT LÝ NẾU ĐÃ GÁN
        $assignments = \App\Models\BookingRoomAssignment::where('booking_id', $booking->id)->get();
        foreach ($assignments as $assignment) {
            \App\Models\Room::where('id', $assignment->room_id)->where('status', 2)->update(['status' => 1]);
            $assignment->delete();
        }

        return response()->json([
            'message' => $refundMessage,
            'needs_refund' => $needsRefund
        ], 200);
    }

    public function getHotelServices(int $hotelId)
    {
        $services = Service::where('hotel_id', $hotelId)
            ->where('type', 1)
            ->where('status', 1)
            ->get();

        return response()->json([
            'message' => 'Lấy danh sách dịch vụ thành công',
            'data' => $services
        ], 200);
    }
}
