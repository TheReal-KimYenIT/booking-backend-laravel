<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialSettingController extends Controller
{
    // Lấy dữ liệu cấu hình tài chính hiện tại
    public function getSettings()
    {
        try {
            // Lấy VAT (Mặc định 10 nếu chưa có trong DB)
            $vatRate = DB::table('ui_settings')
                ->where('setting_key', 'system_vat_rate')
                ->value('setting_value') ?? 10;

            // Lấy Hoa hồng (Mặc định 15 nếu chưa có trong DB)
            $commissionRate = DB::table('ui_settings')
                ->where('setting_key', 'default_commission_rate')
                ->value('setting_value') ?? 15;

            return response()->json([
                'message' => 'Lấy cấu hình thành công',
                'data' => [
                    'system_vat_rate' => (float) $vatRate,
                    'default_commission_rate' => (float) $commissionRate
                ]
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Lỗi: ' . $e->getMessage()], 500);
        }
    }

    // Cập nhật cấu hình tài chính
    public function updateSettings(Request $request)
    {
        $request->validate([
            'system_vat_rate' => 'required|numeric|min:0|max:100',
            'default_commission_rate' => 'required|numeric|min:0|max:100',
        ], [
            'system_vat_rate.required' => 'Vui lòng nhập thuế VAT',
            'default_commission_rate.required' => 'Vui lòng nhập % hoa hồng',
        ]);

        try {
            // Cập nhật hoặc tạo mới VAT
            DB::table('ui_settings')->updateOrInsert(
                ['setting_key' => 'system_vat_rate'],
                [
                    'setting_value' => $request->system_vat_rate,
                    'description' => 'Thuế VAT (%) áp dụng cho toàn hệ thống',
                    'updated_at' => now()
                ]
            );

            // Cập nhật hoặc tạo mới Hoa hồng
            DB::table('ui_settings')->updateOrInsert(
                ['setting_key' => 'default_commission_rate'],
                [
                    'setting_value' => $request->default_commission_rate,
                    'description' => 'Tỉ lệ hoa hồng (%) mặc định thu của khách sạn',
                    'updated_at' => now()
                ]
            );

            return response()->json([
                'message' => 'Lưu cấu hình tài chính thành công!'
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Lỗi khi lưu: ' . $e->getMessage()], 500);
        }
    }
}
