<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Hotel;
use App\Models\Partner;
use Illuminate\Support\Facades\DB;

class PartnerApprovalController extends Controller
{
    // 1. Lấy danh sách đối tác & khách sạn đang chờ duyệt (status = 0)
    public function getPendingPartners()
    {
        $pendingPartners = Hotel::with('partner:id,last_name,first_name,email,phone')
            ->where('status', 0)
            ->get();

        return response()->json(['data' => $pendingPartners], 200);
    }

    // 2. Phê duyệt đối tác (Chuyển status = 1, is_active = 1)
    public function approvePartner(int $hotelId)
    {
        DB::beginTransaction();
        try {
            $hotel = Hotel::findOrFail($hotelId);
            $hotel->status = 1; // 1: Đã duyệt
            $hotel->save();

            $partner = Partner::findOrFail($hotel->partner_id);
            $partner->is_active = 1; // 1: Cho phép đăng nhập
            $partner->save();

            DB::commit();
            return response()->json(['message' => 'Đã phê duyệt đối tác thành công!'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    // 3. Từ chối đối tác (Chuyển status = 2)
    public function rejectPartner(Request $request, int $hotelId)
    {
        $request->validate([
            'reason' => 'required|string|max:255'
        ]);

        DB::beginTransaction();
        try {
            $hotel = Hotel::findOrFail($hotelId);
            $hotel->status = 2; // 2: Bị từ chối

            // $hotel->rejection_reason = $request->reason; // Mở ra nếu bạn có cột này

            $hotel->save();

            DB::commit();
            return response()->json(['message' => 'Đã từ chối khách sạn này!'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    // 4. Lấy danh sách đối tác đang hoạt động (status = 1)
    public function getApprovedPartners()
    {
        $approvedPartners = Hotel::with('partner:id,last_name,first_name,email,phone')
            ->where('status', 1)
            ->get();

        return response()->json(['data' => $approvedPartners], 200);
    }

    // 5. Đình chỉ/Khóa đối tác (Chuyển status = 2, is_active = 0)
    public function suspendPartner(Request $request, int $hotelId)
    {
        $request->validate([
            'reason' => 'required|string|max:255'
        ]);

        DB::beginTransaction();
        try {
            $hotel = Hotel::findOrFail($hotelId);
            $hotel->status = 2; // 2: Bị đình chỉ/từ chối
            // $hotel->rejection_reason = $request->reason; // Mở ra nếu bạn có cột này
            $hotel->save();

            $partner = Partner::findOrFail($hotel->partner_id);
            $partner->is_active = 0; // 0: Khóa không cho đăng nhập nữa
            $partner->save();

            DB::commit();
            return response()->json(['message' => 'Đã khóa tài khoản đối tác thành công!'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
    /**
     * Cập nhật tỉ lệ hoa hồng riêng cho từng khách sạn
     */
    public function updateCommission(Request $request, int $hotelId)
    {
        $request->validate([
            'commission_rate' => 'required|numeric|min:0|max:100'
        ]);

        try {
            $hotel = \App\Models\Hotel::findOrFail($hotelId);
            $hotel->commission_rate = $request->commission_rate;
            $hotel->save();

            return response()->json([
                'message' => 'Cập nhật tỉ lệ hoa hồng thành công!',
                'new_rate' => $hotel->commission_rate
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
}
