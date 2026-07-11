<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\BookingRoomAssignment;
use App\Models\BookingGuest;
use App\Models\Room;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    // ==========================================
    // CÁC HÀM LẤY DỮ LIỆU ĐƠN HÀNG (DÙNG ĐƯỢC CHO LỄ TÂN)
    // ==========================================

    public function index(Request $request)
    {
        // 👉 Đã sử dụng hàm thông minh từ Controller cha
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        $bookings = Booking::with(['details.roomType'])
            ->where('hotel_id', $hotelId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'message' => 'Lấy danh sách đơn hàng thành công',
            'bookings' => $bookings
        ], 200);
    }

    public function show(int $id)
    {
        $hotelId = $this->getHotelId();

        $booking = Booking::with([
            'details.roomType',
            'roomAssignments.room',
            'guests',
            'surcharges',
            'supply_incidents.supply',
            'bookingServices.service'
        ])
            ->where('hotel_id', $hotelId)
            ->findOrFail($id);

        return response()->json([
            'booking' => $booking
        ], 200);
    }

    public function getPaymentInfo(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();

        $booking = Booking::with('payment')
            ->where('id', $id)
            ->where('hotel_id', $hotelId)
            ->first();

        if (!$booking || !$booking->payment) {
            return response()->json(['message' => 'Không tìm thấy thông tin thanh toán'], 404);
        }

        return response()->json([
            'message' => 'Tra cứu thanh toán thành công',
            'payment' => $booking->payment
        ], 200);
    }

    public function confirmBooking(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();
        $booking = Booking::where('id', $id)->where('hotel_id', $hotelId)->first();

        if (!$booking) return response()->json(['message' => 'Không tìm thấy đơn hàng'], 404);

        $booking->update(['status' => 1]);

        return response()->json(['message' => 'Đã xác nhận đơn hàng thành công', 'booking' => $booking], 200);
    }

    public function checkInBooking(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();
        $booking = Booking::where('id', $id)->where('hotel_id', $hotelId)->first();

        if (!$booking) return response()->json(['message' => 'Không tìm thấy đơn hàng'], 404);

        $booking->update(['status' => 2]);

        return response()->json(['message' => 'Đã xử lý nhận phòng thành công', 'booking' => $booking], 200);
    }

    public function cancelBooking(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();
        $booking = Booking::where('id', $id)->where('hotel_id', $hotelId)->first();

        if (!$booking) return response()->json(['message' => 'Không tìm thấy đơn hàng'], 404);

        $booking->update(['status' => 4]);

        return response()->json(['message' => 'Đã hủy đơn hàng thành công', 'booking' => $booking], 200);
    }

    public function checkOutBooking(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();
        $booking = Booking::where('id', $id)->where('hotel_id', $hotelId)->first();

        if (!$booking) return response()->json(['message' => 'Không tìm thấy đơn hàng'], 404);

        $booking->update(['status' => 3]);

        return response()->json(['message' => 'Đã xử lý trả phòng thành công', 'booking' => $booking], 200);
    }

    public function checkIn(Request $request, int $bookingId)
    {
        $request->validate([
            'room_ids' => 'required|array',
            'guests' => 'required|array',
        ]);

        DB::beginTransaction();
        try {
            $booking = Booking::findOrFail($bookingId);

            $booking->update([
                'status' => 2,
                'actual_check_in_at' => now()
            ]);

            foreach ($request->room_ids as $roomId) {
                BookingRoomAssignment::create([
                    'booking_id' => $booking->id,
                    'room_id' => $roomId,
                    'checked_in_at' => now(),
                    'status' => 2
                ]);

                Room::where('id', $roomId)->update(['status' => 2]);
            }

            foreach ($request->guests as $guest) {
                BookingGuest::create([
                    'booking_id' => $booking->id,
                    'full_name' => $guest['full_name'],
                    'identity_number' => $guest['identity_number'] ?? null,
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Nhận phòng thành công!']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Lỗi: ' . $e->getMessage()], 500);
        }
    }

    public function checkOutAndPay(Request $request, int $bookingId)
    {
        $request->validate([
            'payment_method' => 'required|integer|in:1,2,3',
        ]);

        DB::beginTransaction();
        try {
            $booking = Booking::with(['bookingServices', 'bookingSurcharges', 'supply_incidents'])->findOrFail($bookingId);

            $serviceTotal = $booking->bookingServices->sum(function ($item) {
                return $item->price_at_booking * $item->quantity;
            });

            $surchargeTotal = $booking->bookingSurcharges->sum('amount');
            $damageTotal = $booking->supply_incidents->sum(function ($item) {
                return $item->actual_price * $item->quantity;
            });

            // 👉 TÍNH LẠI VAT THEO % VAT ĐÃ CHỐT CỦA ĐƠN NÀY
            $vatRate = $booking->vat_rate ?? 10;
            $taxableAmount = $booking->total_amount + $serviceTotal + $surchargeTotal + $damageTotal - $booking->discount_amount;
            $newVatAmount = max(0, $taxableAmount * ($vatRate / 100));

            $finalInvoiceAmount = $taxableAmount + $newVatAmount;

            $alreadyPaid = $booking->payment_status == 1 ? $booking->total_price : 0;
            $remainingToPay = max(0, $finalInvoiceAmount - $alreadyPaid);

            if ($remainingToPay > 0) {
                Payment::create([
                    'booking_id' => $booking->id,
                    'payment_method' => $request->payment_method,
                    'payment_type' => 2,
                    'amount' => $remainingToPay,
                    'payment_status' => 1,
                    'paid_at' => now(),
                ]);
            }

            // Cập nhật chốt sổ & cập nhật VAT mới nhất vào DB
            $booking->update([
                'status' => 3,
                'payment_status' => 1,
                'actual_check_out_at' => now(),
                'vat_amount' => $newVatAmount,       // 👉 Lưu lại tiền VAT thực tế
                'total_price' => $finalInvoiceAmount
            ]);

            $assignments = BookingRoomAssignment::where('booking_id', $booking->id)->get();
            foreach ($assignments as $assignment) {
                $assignment->update(['checked_out_at' => now(), 'status' => 3]);
                Room::where('id', $assignment->room_id)->update(['status' => 0]);
            }

            DB::commit();
            return response()->json([
                'message' => 'Trả phòng và chốt sổ thành công!',
                'final_amount_paid' => $remainingToPay
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Lỗi: ' . $e->getMessage()], 500);
        }
    }

    public function getAvailableRooms(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();

        $booking = Booking::with('details')->where('id', $id)->where('hotel_id', $hotelId)->first();

        if (!$booking) return response()->json(['message' => 'Không tìm thấy đơn hàng'], 404);

        $roomTypeId = $booking->details->first()->room_type_id ?? null;

        if (!$roomTypeId) {
            return response()->json(['message' => 'Chưa có dữ liệu loại phòng', 'rooms' => []], 200);
        }

        $availableRooms = Room::where('room_type_id', $roomTypeId)
            ->where('status', 1)
            ->get();

        return response()->json([
            'message' => 'Thành công',
            'rooms' => $availableRooms
        ], 200);
    }

    // ==========================================
    // HÀM CẬP NHẬT DANH SÁCH KHÁCH LƯU TRÚ (TAB 1)
    // ==========================================
    public function updateGuests(Request $request, int $id)
    {
        $booking = Booking::findOrFail($id);

        if ($booking->status >= 3) {
            return response()->json(['message' => 'Đơn hàng đã chốt sổ, không thể sửa thông tin khách!'], 403);
        }

        $validated = $request->validate([
            'guests' => 'array',
            'guests.*.full_name' => 'required|string|max:255',
            'guests.*.identity_number' => 'nullable|string|max:50',
        ]);

        DB::beginTransaction();
        try {
            \App\Models\BookingGuest::where('booking_id', $id)->delete();

            if (!empty($validated['guests'])) {
                $guestsData = [];
                foreach ($validated['guests'] as $guest) {
                    $guestsData[] = [
                        'booking_id' => $id,
                        'full_name' => $guest['full_name'],
                        'identity_number' => $guest['identity_number'] ?? null
                    ];
                }
                \App\Models\BookingGuest::insert($guestsData);
            }
            DB::commit();
            return response()->json(['message' => 'Cập nhật danh sách khách thành công!'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Lỗi: ' . $e->getMessage()], 500);
        }
    }

    // ==========================================
    // HÀM ĐỔI PHÒNG CÙNG HẠNG (ROOM MOVE)
    // ==========================================
    public function changeRoom(Request $request, int $id)
    {
        $booking = Booking::findOrFail($id);

        if ($booking->status >= 3) {
            return response()->json(['message' => 'Đơn hàng đã chốt sổ, không thể đổi phòng!'], 403);
        }

        $validated = $request->validate([
            'old_room_id' => 'required|integer',
            'new_room_id' => 'required|integer'
        ]);

        DB::beginTransaction();
        try {
            $assignment = \App\Models\BookingRoomAssignment::where('booking_id', $id)
                ->where('room_id', $validated['old_room_id'])
                ->first();

            if (!$assignment) {
                return response()->json(['message' => 'Không tìm thấy thông tin phòng cũ của khách.'], 404);
            }

            $assignment->update([
                'room_id' => $validated['new_room_id']
            ]);

            \App\Models\Room::where('id', $validated['old_room_id'])->update(['status' => 0]);
            \App\Models\Room::where('id', $validated['new_room_id'])->update(['status' => 2]);

            DB::commit();
            return response()->json(['message' => 'Đổi phòng và điều phối trạng thái buồng phòng thành công!'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Lỗi: ' . $e->getMessage()], 500);
        }
    }

    // ==========================================
    // HÀM CẬP NHẬT GHI CHÚ VÀ LIÊN HỆ (TAB 1)
    // ==========================================
    public function updateBookingNotes(Request $request, int $id)
    {
        $booking = Booking::findOrFail($id);

        if ($booking->status >= 3) {
            return response()->json(['message' => 'Đơn hàng đã chốt sổ, không thể sửa ghi chú!'], 403);
        }

        $validated = $request->validate([
            'guest_phone' => 'nullable|string|max:20',
            'special_requests' => 'nullable|string|max:1000',
        ]);

        $booking->update([
            'guest_phone' => $validated['guest_phone'],
            'special_requests' => $validated['special_requests'],
        ]);

        return response()->json(['message' => 'Cập nhật ghi chú thành công!'], 200);
    }


    // ==========================================
    // CÁC HÀM DÀNH CHO TAB 2: GỌI DỊCH VỤ / MINIBAR
    // ==========================================

    public function getMenuAndCart(int $id)
    {
        $hotelId = $this->getHotelId();

        $allMenu = \App\Models\Service::where('hotel_id', $hotelId)->where('status', 1)->get();
        $menuServices = $allMenu->where('type', 1)->values();
        $menuMinibars = $allMenu->where('type', 2)->values();

        $booking = Booking::with('bookingServices.service')
            ->where('id', $id)
            ->where('hotel_id', $hotelId)
            ->first();

        if (!$booking) return response()->json(['message' => 'Không tìm thấy đơn'], 404);

        $cartServices = $booking->bookingServices->filter(function ($item) {
            return $item->service && $item->service->type == 1;
        })->values();

        $cartMinibars = $booking->bookingServices->filter(function ($item) {
            return $item->service && $item->service->type == 2;
        })->values();

        return response()->json([
            'menu_services' => $menuServices,
            'menu_minibars' => $menuMinibars,
            'cart_services' => $cartServices,
            'cart_minibars' => $cartMinibars
        ], 200);
    }

    public function addExtraService(Request $request, int $id)
    {
        $validated = $request->validate([
            'service_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric',
            'note' => 'nullable|string|max:255'
        ]);
        $booking = \App\Models\Booking::find($id);
        if ($booking && $booking->status >= 3) {
            return response()->json(['message' => 'Đơn hàng đã thanh toán và chốt sổ. Không thể chỉnh sửa!'], 403);
        }
        \App\Models\BookingService::create([
            'booking_id' => $id,
            'service_id' => $validated['service_id'],
            'quantity' => $validated['quantity'],
            'price_at_booking' => $validated['price'],
            'note' => $validated['note'] ?? null,
            'created_at' => now()
        ]);

        return response()->json(['message' => 'Đã thêm vào hóa đơn!'], 200);
    }

    public function addExtraMinibar(Request $request, int $id)
    {
        $validated = $request->validate([
            'minibar_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric',
            'note' => 'nullable|string|max:255'

        ]);
        $booking = \App\Models\Booking::find($id);
        if ($booking && $booking->status >= 3) {
            return response()->json(['message' => 'Đơn hàng đã thanh toán và chốt sổ. Không thể chỉnh sửa!'], 403);
        }
        \App\Models\BookingService::create([
            'booking_id' => $id,
            'service_id' => $validated['minibar_id'],
            'quantity' => $validated['quantity'],
            'price_at_booking' => $validated['price'],
            'note' => $validated['note'] ?? null,
            'created_at' => now()
        ]);

        return response()->json(['message' => 'Đã thêm Minibar vào hóa đơn!'], 200);
    }

    public function updateExtraService(Request $request, int $id, int $cartId)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
            'note' => 'nullable|string|max:255'
        ]);
        $booking = \App\Models\Booking::find($id);
        if ($booking && $booking->status >= 3) {
            return response()->json(['message' => 'Đơn hàng đã thanh toán và chốt sổ. Không thể chỉnh sửa!'], 403);
        }
        $cartItem = \App\Models\BookingService::where('id', $cartId)
            ->where('booking_id', $id)
            ->first();

        if (!$cartItem) {
            return response()->json(['message' => 'Không tìm thấy món'], 404);
        }

        $cartItem->update([
            'quantity' => $validated['quantity'],
            'note' => $validated['note']
        ]);

        return response()->json(['message' => 'Cập nhật thành công!'], 200);
    }

    public function removeExtraService(Request $request, int $id, int $cartId)
    {
        $booking = \App\Models\Booking::find($id);
        if ($booking && $booking->status >= 3) {
            return response()->json(['message' => 'Đơn hàng đã thanh toán và chốt sổ. Không thể chỉnh sửa!'], 403);
        }

        $cartItem = \App\Models\BookingService::where('id', $cartId)
            ->where('booking_id', $id)
            ->first();

        if (!$cartItem) {
            return response()->json(['message' => 'Không tìm thấy món này trong giỏ hàng'], 404);
        }

        $cartItem->delete();

        return response()->json(['message' => 'Đã xóa món thành công!'], 200);
    }


    public function addSurcharge(Request $request, int $id)
    {
        $request->validate([
            'surcharge_category_id' => 'required|integer',
            'amount' => 'required|numeric|min:0',
            'note' => 'nullable|string'
        ]);

        $surcharge = DB::table('booking_surcharges')->insert([
            'booking_id' => $id,
            'surcharge_category_id' => $request->surcharge_category_id,
            'amount' => $request->amount,
            'note' => $request->note,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Đã thêm khoản phụ thu thành công!',
            'data' => $surcharge
        ], 200);
    }

    public function removeSurcharge(int $id, int $surchargeId)
    {
        $deleted = DB::table('booking_surcharges')
            ->where('booking_id', $id)
            ->where('id', $surchargeId)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Không tìm thấy khoản phụ thu để xóa!'], 404);
        }

        return response()->json([
            'message' => 'Đã xóa khoản phụ thu thành công!'
        ], 200);
    }



    // 1. Thêm Đền bù vật tư
    public function addDamagedItem(Request $request, int $id)
    {
        $request->validate([
            'supply_id' => 'required|integer',
            'incident_type' => 'required|integer|in:1,2',
            'quantity' => 'required|integer|min:1',
            'actual_price' => 'required|numeric|min:0',
            'note' => 'nullable|string'
        ]);

        DB::table('supply_incidents')->insert([
            'booking_id' => $id,
            'supply_id' => $request->supply_id,
            'incident_type' => $request->incident_type,
            'quantity' => $request->quantity,
            'actual_price' => $request->actual_price,
            'reason' => $request->note,
            'reported_by' => auth('partner')->id() ?? 1, // 👉 Nếu kỹ tính thì bạn có thể giữ nguyên vì nó lưu lại đúng người đã khai báo lỗi
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Đã thêm phí đền bù tài sản thành công!'], 200);
    }

    // 2. Xóa Đền bù vật tư
    public function removeDamagedItem(int $id, int $itemId)
    {
        DB::table('supply_incidents')->where('booking_id', $id)->where('id', $itemId)->delete();
        return response()->json(['message' => 'Đã xóa khoản đền bù thành công!'], 200);
    }


    // 1. CẬP NHẬT GIỜ HẸN (ĐẾN TRỄ HOẶC ĐI TRỄ)
    public function updateEstimatedTime(Request $request, int $id)
    {
        $booking = \App\Models\Booking::findOrFail($id);

        if ($request->has('estimated_arrival_time')) {
            $booking->estimated_arrival_time = $request->estimated_arrival_time;
        }

        if ($request->has('estimated_departure_time')) {
            $booking->estimated_departure_time = $request->estimated_departure_time;
        }

        $booking->save();

        return response()->json(['message' => 'Đã cập nhật giờ hẹn của khách thành công!'], 200);
    }

    // 2. ĐÁNH DẤU KHÁCH KHÔNG ĐẾN (NO-SHOW)
    // 👉 Thêm vào class BookingController
    public function markAsNoShow(int $id)
    {
        $hotelId = $this->getHotelId();
        $booking = Booking::where('id', $id)->where('hotel_id', $hotelId)->with('roomAssignments')->first();

        if (!$booking) return response()->json(['message' => 'Không tìm thấy đơn hàng'], 404);
        if ($booking->status != 1) return response()->json(['message' => 'Chỉ đơn hàng đã xác nhận mới có thể đánh dấu No-show'], 400);

        DB::transaction(function () use ($booking) {
            // Nếu khách chưa cọc/thanh toán, ta hủy doanh thu để kế toán không bị lệch
            if ($booking->payment_status == 0) {
                $booking->update(['total_amount' => 0, 'total_price' => 0]);
            }

            $booking->update(['status' => 5]); // 5: Trạng thái No-show

            // Giải phóng phòng vật lý về trạng thái 1 (Trống)
            if ($booking->roomAssignments->isNotEmpty()) {
                $roomIds = $booking->roomAssignments->pluck('room_id');
                Room::whereIn('id', $roomIds)->update(['status' => 1]);
            }
        });

        return response()->json(['message' => 'Đã xử lý No-show thành công!']);
    }

    // ==========================================
    // HÀM XUẤT HÓA ĐƠN PDF CHO KHÁCH HÀNG (FOLIO)
    // ==========================================
    public function exportInvoice(int $id)
    {
        try {
            $hotelId = $this->getHotelId();

            // 1. Lấy dữ liệu đơn hàng (Kèm các dịch vụ phát sinh)
            $booking = Booking::with([
                'details.roomType',
                'bookingServices.service',
                'bookingSurcharges.category', // Phụ thu
                'supply_incidents.supply',    // Đền bù
            ])->where('id', $id)->where('hotel_id', $hotelId)->firstOrFail();

            $hotel = Hotel::find($hotelId);

            // 2. Tính toán tiền (Đồng bộ với logic Frontend)
            $roomTotal = $booking->details->sum('subtotal');

            $serviceTotal = $booking->bookingServices->sum(function ($item) {
                return $item->price_at_booking * $item->quantity;
            });

            $surchargeTotal = $booking->bookingSurcharges->sum('amount');

            $damageTotal = $booking->supply_incidents->sum(function ($item) {
                return $item->actual_price * $item->quantity;
            });

            $discount = $booking->discount_amount ?? 0;

            // Tính VAT (Chỉ tính trên phần phải thu)
            $taxableAmount = max(0, $roomTotal + $serviceTotal + $surchargeTotal + $damageTotal - $discount);
            $vatRate = $booking->vat_rate ?? 10;
            $vatAmount = $taxableAmount * ($vatRate / 100);

            $finalTotal = $taxableAmount + $vatAmount;

            $alreadyPaid = ($booking->payment_status == 1) ? $booking->total_price : 0;
            $remaining = max(0, $finalTotal - $alreadyPaid);

            $checkIn = \Carbon\Carbon::parse($booking->check_in);
            $checkOut = \Carbon\Carbon::parse($booking->check_out);
            $nights = $checkIn->diffInDays($checkOut);
            $nights = $nights > 0 ? $nights : 1;

            // 3. Đóng gói dữ liệu gửi qua View PDF
            $data = [
                'hotel' => $hotel,
                'booking' => $booking,
                'roomTotal' => $roomTotal,
                'serviceTotal' => $serviceTotal,
                'surchargeTotal' => $surchargeTotal,
                'damageTotal' => $damageTotal,
                'discount' => $discount,
                'vatRate' => $vatRate,
                'vatAmount' => $vatAmount,
                'finalTotal' => $finalTotal,
                'alreadyPaid' => $alreadyPaid,
                'remaining' => $remaining,
                'nights' => $nights,
                'print_date' => now()->format('d/m/Y H:i:s')
            ];
            // 4. Render HTML và xuất PDF
            $html = view('pdf.invoice', $data)->render();

            $options = new \Dompdf\Options();
            $options->set('defaultFont', 'DejaVu Sans'); // Hỗ trợ Tiếng Việt
            $options->set('isRemoteEnabled', true);

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $fileName = "Hoa_Don_{$booking->booking_code}.pdf";

            return response($dompdf->output(), 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Lỗi xuất PDF Invoice: ' . $e->getMessage());
            return response()->json(['message' => 'Lỗi tạo PDF: ' . $e->getMessage()], 500);
        }
    }
}
