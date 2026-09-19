<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index()
    {
        try {
            $partner = auth('partner')->user();
            $hotel = DB::table('hotels')->where('partner_id', $partner->parent_id ?? $partner->id)->first();

            if (!$hotel) {
                return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);
            }

            // Lấy danh sách khách hàng duy nhất thông qua bảng bookings
            $customers = DB::table('customers')
                ->join('bookings', 'customers.id', '=', 'bookings.customer_id')
                ->where('bookings.hotel_id', $hotel->id)
                ->select(
                    'customers.id',
                    'customers.first_name',
                    'customers.last_name',
                    'customers.email',
                    'customers.phone',
                    'customers.gender',
                    'customers.created_at',
                    DB::raw('COUNT(bookings.id) as total_bookings'),
                    DB::raw('ROUND(COALESCE(SUM(CASE WHEN bookings.status != 4 THEN bookings.total_amount ELSE 0 END), 0)) as total_spent'),
                    DB::raw('MAX(bookings.created_at) as latest_booking_at')
                )
                ->groupBy('customers.id', 'customers.first_name', 'customers.last_name', 'customers.email', 'customers.phone', 'customers.gender', 'customers.created_at')
                ->orderBy('total_bookings', 'desc')
                ->get();

            // Lấy toàn bộ thông tin blacklist để lấy được lý do (reason)
            $blacklists = DB::table('hotel_blacklists')
                ->where('hotel_id', $hotel->id)
                ->get()
                ->keyBy('customer_id');

            // Gắn trạng thái khóa và lý do vào từng khách hàng
            foreach ($customers as $customer) {
                $customer->total_spent = (int) round((float) ($customer->total_spent ?? 0));
                $customer->total_bookings = (int) ($customer->total_bookings ?? 0);

                if ($blacklists->has($customer->id)) {
                    $customer->is_blocked = 1;
                    $customer->block_reason = $blacklists->get($customer->id)->reason;
                } else {
                    $customer->is_blocked = 0;
                    $customer->block_reason = null;
                }
            }

            return response()->json(['data' => $customers], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    // Chặn / Bỏ chặn khách hàng
    public function toggleBlock(Request $request, int $customerId)
    {
        try {
            $partner = auth('partner')->user();
            $hotel = DB::table('hotels')->where('partner_id', $partner->parent_id ?? $partner->id)->first();

            if (!$hotel) {
                return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);
            }

            $customer = DB::table('customers')->where('id', $customerId)->first();
            if (!$customer) {
                return response()->json(['message' => 'Không tìm thấy khách hàng'], 404);
            }

            $blocked = DB::table('hotel_blacklists')
                ->where('hotel_id', $hotel->id)
                ->where('customer_id', $customerId)
                ->first();

            if ($blocked) {
                // Đang chặn -> Mở khóa (Xóa khỏi danh sách đen)
                DB::table('hotel_blacklists')->where('id', $blocked->id)->delete();
                return response()->json(['message' => 'Đã MỞ KHÓA cho khách hàng này.'], 200);
            } else {
                //  Nhận lý do từ Frontend và lưu đầy đủ thông tin
                $reason = $request->input('reason', 'Không có lý do');

                DB::table('hotel_blacklists')->insert([
                    'hotel_id' => $hotel->id,
                    'customer_id' => $customer->id,
                    'reason' => $reason,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                return response()->json(['message' => 'Đã ĐƯA VÀO DANH SÁCH ĐEN khách hàng này.'], 200);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }


    // Thêm hàm này vào dưới cùng của class CustomerController
    public function getCustomerBookings(int $customerId)
    {
        try {
            $partner = auth('partner')->user();
            $hotel = \Illuminate\Support\Facades\DB::table('hotels')->where('partner_id', $partner->parent_id ?? $partner->id)->first();

            if (!$hotel) {
                return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);
            }

            // Dùng Eloquent để lấy lịch sử đơn hàng kèm tên loại phòng
            $bookings = \App\Models\Booking::with(['details.roomType'])
                ->where('hotel_id', $hotel->id)
                ->where('customer_id', $customerId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json(['data' => $bookings], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
}
