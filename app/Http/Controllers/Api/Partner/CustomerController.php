<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    // Lấy danh sách khách hàng đã từng đặt phòng tại khách sạn của Partner
    public function index()
    {
        try {
            $partner = auth('partner')->user();
            $hotel = DB::table('hotels')->where('partner_id', $partner->id)->first();

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
                    DB::raw('COUNT(bookings.id) as total_bookings')
                )
                ->groupBy('customers.id', 'customers.first_name', 'customers.last_name', 'customers.email', 'customers.phone', 'customers.gender', 'customers.created_at')
                ->orderBy('total_bookings', 'desc')
                ->get();

            // Lấy danh sách ID khách hàng đang bị khách sạn này khóa
            $blockedIds = DB::table('hotel_blacklists')
                ->where('hotel_id', $hotel->id)
                ->pluck('customer_id')
                ->toArray();

            // Gắn trạng thái khóa vào từng khách hàng
            foreach ($customers as $customer) {
                $customer->is_blocked = in_array($customer->id, $blockedIds) ? 1 : 0;
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
            $hotel = DB::table('hotels')->where('partner_id', $partner->id)->first();

            if (!$hotel) {
                return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);
            }

            $customer = DB::table('customers')->where('id', $customerId)->first();
            if (!$customer) {
                return response()->json(['message' => 'Không tìm thấy khách hàng'], 404);
            }

            // Kiểm tra xem khách đã bị chặn chưa
            $blocked = DB::table('hotel_blacklists')
                ->where('hotel_id', $hotel->id)
                ->where('customer_id', $customerId)
                ->first();

            if ($blocked) {
                // Đang chặn -> Mở khóa (Xóa khỏi danh sách đen)
                DB::table('hotel_blacklists')->where('id', $blocked->id)->delete();
                return response()->json(['message' => 'Đã MỞ KHÓA cho khách hàng này.'], 200);
            } else {
                // Chưa chặn -> Thêm vào danh sách đen (Chặn cả ID và số điện thoại)
                DB::table('hotel_blacklists')->insert([
                    'hotel_id' => $hotel->id,
                    'customer_id' => $customer->id,
                    'phone' => $customer->phone,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                return response()->json(['message' => 'Đã ĐƯA VÀO DANH SÁCH ĐEN khách hàng này.'], 200);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
}
