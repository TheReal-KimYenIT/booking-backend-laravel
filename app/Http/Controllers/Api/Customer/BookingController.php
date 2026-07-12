<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\RoomType;
use App\Models\BookingDetail;
use App\Models\RoomInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class BookingController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'room_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
            'guest_name' => 'required|string|max:255',
            'guest_phone' => 'required|string|max:20',
            'guest_email' => 'required|email',
            'total_price' => 'required|numeric'
        ]);

        $room = RoomType::find($request->room_id);

        if (!$room) {
            return response()->json(['message' => 'Không tìm thấy loại phòng'], 404);
        }

        $checkInDate = Carbon::parse($request->check_in);
        $checkOutDate = Carbon::parse($request->check_out);
        $nights = $checkInDate->diffInDays($checkOutDate);

        if ($nights <= 0) {
            $nights = 1;
        }

        $calculatedTotal = $room->base_price * $nights;
        $bookingCode = 'SB-' . strtoupper(Str::random(7));

        $booking = Booking::create([
            'booking_code' => $bookingCode,
            'customer_id' => auth('customer')->id(),
            'hotel_id' => $room->hotel_id,
            'check_in' => $request->check_in,
            'check_out' => $request->check_out,
            'guest_name' => $request->guest_name,
            'guest_phone' => $request->guest_phone,
            'guest_email' => $request->guest_email,
            'note' => $request->note,

            'total_price' => $calculatedTotal,
            'total_amount' => $calculatedTotal,
            'room_type_id' => $request->room_id,

            'status' => 0,
        ]);

        return response()->json([
            'message' => 'Đặt phòng thành công!',
            'data' => $booking
        ], 201);
    }
    public function createBooking(Request $request)
    {
        $request->validate([
            'hotel_id' => 'required|exists:hotels,id',
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
            'guest_name' => 'required|string',
            'guest_phone' => 'required|string',
            'guest_email' => 'required|email',
            'rooms_count' => 'required|integer|min:1',
            'note' => 'nullable|string',
            'services' => 'nullable|array',
            'services.*.id' => 'required_with:services|integer',
            'services.*.quantity' => 'required_with:services|integer|min:1',
            'global_promotion_code' => 'nullable|string',
            'hotel_promotion_code' => 'nullable|string'
        ]);

        try {
            $result = DB::transaction(function () use ($request) {
                $customerId = auth('customer')->id();
                $roomType = RoomType::find($request->room_type_id);
                if (!$roomType) throw new \Exception('Không tìm thấy loại phòng hợp lệ.');

                // 👉 1. CHỤP NHANH (SNAPSHOT) CẤU HÌNH % TỪ DATABASE
                $uiSettings = DB::table('ui_settings')->pluck('setting_value', 'setting_key');
                $systemVatRate = isset($uiSettings['vat_rate']) ? (float)$uiSettings['vat_rate'] : 10;
                $defaultCommission = isset($uiSettings['default_commission_rate']) ? (float)$uiSettings['default_commission_rate'] : 15;

                $hotel = \App\Models\Hotel::find($request->hotel_id);
                $hotelCommissionRate = $hotel->commission_rate ?? $defaultCommission;

                $checkIn = Carbon::parse($request->check_in);
                $checkOut = Carbon::parse($request->check_out);
                $nights = $checkIn->diffInDays($checkOut);
                if ($nights <= 0) $nights = 1;

                $subtotal = 0;
                $currentDate = $checkIn->copy();

                for ($i = 0; $i < $nights; $i++) {
                    $dateStr = $currentDate->format('Y-m-d');
                    $inventory = RoomInventory::where('room_type_id', $roomType->id)
                        ->where('apply_date', $dateStr)
                        ->first();

                    if ($inventory && (int)$inventory->is_closed === 1) {
                        throw new \Exception("Rất tiếc, loại phòng này đã dừng nhận khách vào ngày " . $currentDate->format('d/m/Y'));
                    }

                    $dailyPrice = $inventory ? $inventory->price : $roomType->base_price;
                    $subtotal += $dailyPrice;
                    $currentDate->addDay();
                }

                $subtotal = $subtotal * $request->rooms_count;

                // TÍNH DỊCH VỤ 
                $servicesTotal = 0;
                $requestedServices = [];
                if ($request->has('services') && count($request->services) > 0) {
                    $requestedServices = \App\Models\Service::whereIn('id', array_column($request->services, 'id'))->get();
                    foreach ($requestedServices as $srv) {
                        $qty = collect($request->services)->firstWhere('id', $srv->id)['quantity'] ?? 1;
                        $servicesTotal += ($srv->price * $qty);
                    }
                }

                // XỬ LÝ KHUYẾN MÃI KÉP
                $globalDiscount = 0;
                $hotelDiscount = 0;
                $globalPromoId = null;
                $hotelPromoId = null;

                if ($request->has('global_promotion_code') && !empty($request->global_promotion_code)) {
                    $promo = \App\Models\Promotion::where('code', $request->global_promotion_code)
                        ->whereNull('hotel_id')->lockForUpdate()->first();

                    if ($promo && $promo->status == 1 && $promo->used_count < ($promo->usage_limit ?? 999999999)) {
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

                $subtotalAfterGlobal = $subtotal - $globalDiscount;

                if ($request->has('hotel_promotion_code') && !empty($request->hotel_promotion_code)) {
                    $promo = \App\Models\Promotion::where('code', $request->hotel_promotion_code)
                        ->where('hotel_id', $request->hotel_id)->lockForUpdate()->first();

                    if ($promo && $promo->status == 1 && $promo->used_count < ($promo->usage_limit ?? 999999999)) {
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

                $totalDiscount = $globalDiscount + $hotelDiscount;

                // 👉 2. TÍNH VAT TRÊN TỔNG HÓA ĐƠN THEO % HIỆN TẠI
                $taxableAmount = $subtotal + $servicesTotal - $totalDiscount;
                $tax = max(0, $taxableAmount * ($systemVatRate / 100));

                $totalAmount = $taxableAmount + $tax;

                // 👉 3. LƯU CỨNG % VAT VÀ HOA HỒNG VÀO ĐƠN
                $booking = Booking::create([
                    'booking_code' => 'SB-' . strtoupper(Str::random(8)),
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

                    'total_amount' => $subtotal + $servicesTotal,
                    'total_price' => $totalAmount,
                    'discount_amount' => $totalDiscount,

                    'vat_amount' => $tax,
                    'vat_rate' => $systemVatRate,               // Snapshot VAT
                    'commission_rate' => $hotelCommissionRate,  // Snapshot Hoa hồng

                    'status' => 0,
                    'payment_status' => 0,
                ]);

                BookingDetail::create([
                    'booking_id' => $booking->id,
                    'room_type_id' => $request->room_type_id,
                    'check_in_date' => $request->check_in,
                    'check_out_date' => $request->check_out,
                    'rooms_count' => $request->rooms_count,
                    'subtotal' => $subtotal
                ]);

                if (count($requestedServices) > 0) {
                    foreach ($requestedServices as $srv) {
                        $qty = collect($request->services)->firstWhere('id', $srv->id)['quantity'] ?? 1;
                        \App\Models\BookingService::create([
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
                'booking_id' => $result->id
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
    public function myBookings(Request $request)
    {
        $bookings = Booking::with([
            'details.roomType',
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
    public function cancelMyBooking(Request $request, int $id)
    {
        $customer = auth('sanctum')->user();

        $booking = DB::table('bookings')
            ->where('id', $id)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$booking) {
            return response()->json(['message' => 'Không tìm thấy đơn đặt phòng!'], 404);
        }

        if ($booking->status != 0 && $booking->status != 1) {
            return response()->json(['message' => 'Không thể hủy đơn hàng ở trạng thái này!'], 403);
        }

        // 1. Tính toán thời gian chênh lệch so với giờ Check-in (Mặc định 14:00)
        $now = \Carbon\Carbon::now();
        $checkInDateTime = \Carbon\Carbon::parse($booking->check_in)->setHour(14)->setMinute(0);
        $hoursDifference = $now->diffInHours($checkInDateTime, false);

        if ($hoursDifference <= 0) {
            return response()->json(['message' => 'Đã qua giờ nhận phòng, không thể hủy!'], 403);
        }

        $payment = DB::table('payments')->where('booking_id', $booking->id)->first();
        $isPrepaid = ($payment && $payment->payment_method == 4 && $payment->payment_status == 1);

        $refundAmount = 0;
        $needsRefund = false;
        $refundMessage = '';

        // 2. CHÍNH SÁCH HỦY PHÒNG
        if ($hoursDifference >= 48) {
            // Hủy trước 48h -> Hoàn 100%
            if ($isPrepaid) {
                // Kiểm tra xem khách đã gửi thông tin ngân hàng chưa
                $request->validate([
                    'refund_bank' => 'required|string',
                    'refund_account' => 'required|string',
                    'refund_account_name' => 'required|string',
                ], [
                    'refund_bank.required' => 'Vui lòng cung cấp Tên ngân hàng để nhận tiền hoàn.',
                    'refund_account.required' => 'Vui lòng cung cấp Số tài khoản.',
                    'refund_account_name.required' => 'Vui lòng cung cấp Tên chủ tài khoản.',
                ]);

                $needsRefund = true;
                $refundAmount = $payment->amount;
                $refundMessage = 'Hủy phòng thành công. Admin sẽ chuyển khoản hoàn tiền cho bạn trong vòng 24h.';
            } else {
                $refundMessage = 'Hủy phòng thành công!';
            }
        } else {
            // Hủy trong vòng 48h -> Phạt 100% (Không hoàn tiền)
            if ($isPrepaid) {
                $refundMessage = 'Hủy phòng thành công. Bạn hủy trong vòng 48h nên không được hoàn tiền theo chính sách.';
            } else {
                $refundMessage = 'Hủy phòng thành công!';
            }
        }

        // 3. Cập nhật trạng thái đơn hàng và thông tin ngân hàng (nếu có)
        DB::table('bookings')->where('id', $id)->update([
            'status' => 4, // 4: Đã hủy
            'refund_status' => $needsRefund ? 1 : 0, // 1: Đưa vào danh sách chờ Admin hoàn tiền
            'refund_amount' => $refundAmount,
            'refund_bank' => $request->refund_bank ?? null,
            'refund_account' => $request->refund_account ?? null,
            'refund_account_name' => strtoupper($request->refund_account_name ?? ''),
            'updated_at' => now()
        ]);

        // 4. TRẢ LẠI PHÒNG TRỐNG VÀO KHO (Room Inventory)
        $bookingDetails = DB::table('booking_details')->where('booking_id', $booking->id)->get();
        foreach ($bookingDetails as $detail) {
            $checkIn = Carbon::parse($detail->check_in_date);
            $checkOut = Carbon::parse($detail->check_out_date);
            $nights = $checkIn->diffInDays($checkOut) ?: 1;

            for ($i = 0; $i < $nights; $i++) {
                $dateStr = $checkIn->copy()->addDays($i)->format('Y-m-d');
                // Lưu ý: Đổi 'booked_rooms' thành tên cột số lượng phòng đã đặt trong database của bạn
                // DB::table('room_inventories')
                //     ->where('room_type_id', $detail->room_type_id)
                //     ->where('apply_date', $dateStr)
                //     ->decrement('booked_rooms', $detail->rooms_count);
            }
        }

        return response()->json([
            'message' => $refundMessage,
            'needs_refund' => $needsRefund
        ], 200);
    }

    public function getHotelServices(int $hotelId)
    {
        $services = \App\Models\Service::where('hotel_id', $hotelId)
            ->where('type', 1)
            ->where('status', 1)
            ->get();

        return response()->json([
            'message' => 'Lấy danh sách dịch vụ thành công',
            'data' => $services
        ], 200);
    }
}
