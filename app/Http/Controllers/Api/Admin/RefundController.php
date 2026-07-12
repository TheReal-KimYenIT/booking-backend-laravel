<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundController extends Controller
{
    /**
     * Lấy danh sách các đơn hàng cần hoàn tiền hoặc đã hoàn tiền
     */
    public function index()
    {
        $refunds = DB::table('bookings')
            ->where('status', 4) // Trạng thái: Đã hủy
            ->whereIn('refund_status', [1, 2]) // 1: Chờ hoàn tiền, 2: Đã hoàn tiền
            ->orderBy('refund_status', 'asc') // Ưu tiên xếp các đơn chờ hoàn lên trên
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'message' => 'Lấy danh sách hoàn tiền thành công',
            'data' => $refunds
        ], 200);
    }

    /**
     * Admin xác nhận đã chuyển khoản hoàn tiền thành công
     */
    public function confirmRefund(int $id)
    {
        $booking = DB::table('bookings')->where('id', $id)->first();

        if (!$booking) {
            return response()->json(['message' => 'Không tìm thấy đơn hàng!'], 404);
        }

        if ($booking->refund_status != 1) {
            return response()->json(['message' => 'Đơn hàng này không ở trạng thái chờ hoàn tiền!'], 400);
        }

        // Cập nhật trạng thái thành Đã hoàn tiền (2)
        DB::table('bookings')->where('id', $id)->update([
            'refund_status' => 2,
            'updated_at' => now()
        ]);

        return response()->json(['message' => 'Xác nhận hoàn tiền thành công!'], 200);
    }
}
