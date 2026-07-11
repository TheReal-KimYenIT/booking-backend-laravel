<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemSettingController extends Controller
{
    /**
     * Lấy cấu hình tài chính hiện tại
     */
    public function index()
    {
        try {
            // Lấy tất cả dữ liệu trong bảng ui_settings dạng Key => Value
            $settings = DB::table('ui_settings')->pluck('setting_value', 'setting_key');

            return response()->json([
                'message' => 'Thành công',
                'data' => [
                    // Mặc định là 10% và 15% nếu trong Database chưa có
                    'vat_rate' => isset($settings['vat_rate']) ? (float) $settings['vat_rate'] : 10,
                    'default_commission_rate' => isset($settings['default_commission_rate']) ? (float) $settings['default_commission_rate'] : 15,
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
            // Cập nhật hoặc Thêm mới Thuế VAT
            DB::table('ui_settings')->updateOrInsert(
                ['setting_key' => 'vat_rate'],
                [
                    'setting_value' => $request->vat_rate,
                    'description' => 'Thuế Giá trị gia tăng (VAT %) áp dụng cho toàn hệ thống',
                    'updated_at' => now()
                ]
            );

            // Cập nhật hoặc Thêm mới Tỉ lệ hoa hồng
            DB::table('ui_settings')->updateOrInsert(
                ['setting_key' => 'default_commission_rate'],
                [
                    'setting_value' => $request->default_commission_rate,
                    'description' => 'Tỉ lệ hoa hồng mặc định (%) thu của đối tác',
                    'updated_at' => now()
                ]
            );

            return response()->json(['message' => 'Đã lưu cấu hình tài chính thành công!'], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
}
