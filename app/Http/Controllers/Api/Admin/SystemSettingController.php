<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SystemSettingController extends Controller
{
    /**
     * Lấy cấu hình tài chính hiện tại kèm dữ liệu thống kê từ CSDL
     */
    public function index()
    {
        try {
            // Lấy tất cả dữ liệu trong bảng system_settings dạng Key => Value
            $settings = DB::table('system_settings')->pluck('setting_value', 'setting_key');
            $latestUpdate = DB::table('system_settings')->max('updated_at');

            // Số liệu thực tế trực tiếp từ DB
            $totalHotels = DB::table('hotels')->count();
            $totalBookings = DB::table('bookings')->count();
            $totalPlatformFee = (float) DB::table('bookings')->whereIn('status', [1, 2, 3])->sum('platform_fee');
            $totalVatCollected = (float) DB::table('bookings')->whereIn('status', [1, 2, 3])->sum('vat_amount');

            return response()->json([
                'message' => 'Thành công',
                'data' => [
                    // Mặc định là 10% và 14% nếu trong Database chưa có
                    'vat_rate' => isset($settings['vat_rate']) ? (float) $settings['vat_rate'] : 10,
                    'default_commission_rate' => isset($settings['default_commission_rate']) ? (float) $settings['default_commission_rate'] : 14,
                    'updated_at' => $latestUpdate ? Carbon::parse($latestUpdate)->format('d/m/Y H:i') : 'Mặc định',
                    'total_hotels' => $totalHotels,
                    'total_bookings' => $totalBookings,
                    'total_platform_fee' => round($totalPlatformFee),
                    'total_vat_collected' => round($totalVatCollected),
                ]
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Cập nhật cấu hình tài chính
     */
    public function update(Request $request)
    {
        $request->validate([
            'vat_rate' => 'required|numeric|min:0|max:100',
            'default_commission_rate' => 'required|numeric|min:0|max:100',
        ]);

        try {
            $vatRate = round((float) $request->vat_rate, 2);
            $commissionRate = round((float) $request->default_commission_rate, 2);
            $now = now();

            // Cập nhật hoặc Thêm mới Thuế VAT
            DB::table('system_settings')->updateOrInsert(
                ['setting_key' => 'vat_rate'],
                [
                    'setting_value' => (string) $vatRate,
                    'description' => 'Thuế Giá trị gia tăng (VAT %) áp dụng cho toàn hệ thống',
                    'updated_at' => $now
                ]
            );

            // Cập nhật hoặc Thêm mới Tỉ lệ hoa hồng
            DB::table('system_settings')->updateOrInsert(
                ['setting_key' => 'default_commission_rate'],
                [
                    'setting_value' => (string) $commissionRate,
                    'description' => 'Tỉ lệ hoa hồng mặc định (%) thu của đối tác',
                    'updated_at' => $now
                ]
            );

            return response()->json([
                'message' => 'Đã lưu cấu hình tài chính thành công!',
                'data' => [
                    'vat_rate' => $vatRate,
                    'default_commission_rate' => $commissionRate,
                    'updated_at' => $now->format('d/m/Y H:i'),
                ]
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
}
