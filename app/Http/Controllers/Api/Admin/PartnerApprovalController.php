<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Hotel;
use App\Models\Partner;
use Illuminate\Support\Facades\DB;

class PartnerApprovalController extends Controller
{
    // Lấy danh sách đối tác đang chờ admin duyệt.
    public function getPendingPartners()
    {
        $pendingPartners = Hotel::with('partner:id,last_name,first_name,email,phone')
            ->where('status', 0)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['data' => $pendingPartners], 200);
    }

    // Phê duyệt tài khoản đối tác và cho phép hoạt động.
    public function approvePartner(int $hotelId)
    {
        DB::beginTransaction();
        try {
            $hotel = Hotel::findOrFail($hotelId);
            $hotel->status = 1; // 1: Đã duyệt
            $hotel->save();

            if ($hotel->partner_id) {
                $partner = Partner::find($hotel->partner_id);
                if ($partner) {
                    $partner->is_active = 1; // 1: Cho phép đăng nhập
                    $partner->save();
                }
            }

            DB::commit();
            return response()->json(['message' => 'Đã phê duyệt đối tác thành công!'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    // Từ chối đối tác và lưu lý do.
    public function rejectPartner(Request $request, int $hotelId)
    {
        $request->validate([
            'reason' => 'required|string|max:255'
        ]);

        DB::beginTransaction();
        try {
            $hotel = Hotel::findOrFail($hotelId);
            $hotel->status = 2; // 2: Bị từ chối

            $hotel->rejection_reason = $request->reason;

            $hotel->save();

            DB::commit();
            return response()->json(['message' => 'Đã từ chối khách sạn này!'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    // Lấy danh sách đối tác đã được duyệt.
    public function getApprovedPartners()
    {
        $approvedPartners = Hotel::with('partner:id,last_name,first_name,email,phone')
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['data' => $approvedPartners], 200);
    }

    // Khóa đối tác nếu phát hiện vi phạm hoặc cần đình chỉ.
    public function suspendPartner(Request $request, int $hotelId)
    {
        $request->validate([
            'reason' => 'required|string|max:255'
        ]);

        DB::beginTransaction();
        try {
            $hotel = Hotel::findOrFail($hotelId);
            $hotel->status = 2; // 2: Bị đình chỉ/từ chối
            $hotel->rejection_reason = $request->reason;
            $hotel->save();

            if ($hotel->partner_id) {
                $partner = Partner::find($hotel->partner_id);
                if ($partner) {
                    $partner->is_active = 0; // 0: Khóa không cho đăng nhập nữa
                    $partner->save();
                }
            }

            DB::commit();
            return response()->json(['message' => 'Đã khóa tài khoản đối tác thành công!'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
    // Cập nhật phần trăm hoa hồng riêng cho từng khách sạn.
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
