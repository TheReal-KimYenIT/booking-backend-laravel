<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // ===================================================
    // 1. HÀM ĐĂNG NHẬP (QUAN TRỌNG NHẤT ĐỂ LẤY QUYỀN)
    // ===================================================
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Tìm user theo email
        $partner = Partner::with('role')->where('email', $request->email)->first();

        // Kiểm tra sai email hoặc mật khẩu (so sánh với cột password_hash)
        if (!$partner || !Hash::check($request->password, $partner->password_hash)) {
            return response()->json([
                'message' => 'Email hoặc mật khẩu không chính xác!'
            ], 401);
        }

        // Kiểm tra xem tài khoản có bị Chủ KS khóa không
        if ($partner->is_active == 0) {
            return response()->json([
                'message' => 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản lý!'
            ], 403);
        }

        // Tạo token đăng nhập (Sanctum)
        $token = $partner->createToken('partner_token')->plainTextToken;

        // TRẢ VỀ DỮ LIỆU ĐẦY ĐỦ (Bao gồm cả role_id để Angular phân quyền)
        return response()->json([
            'message' => 'Đăng nhập thành công',
            'token' => $token,
            'user' => $partner // Biến $partner này chứa đầy đủ id, role_id, parent_id...
        ], 200);
    }

    // ===================================================
    // 2. HÀM LẤY THÔNG TIN PROFILE CƠ BẢN
    // ===================================================
    public function getProfile(Request $request)
    {
        return response()->json([
            'message' => 'Lấy thông tin thành công',
            'user' => $request->user()
        ], 200);
    }

    // ===================================================
    // 3. HÀM CẬP NHẬT PROFILE
    // ===================================================
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $rules = [
            'last_name' => 'required|string|max:100',
            'first_name' => 'required|string|max:50',
            'phone' => 'nullable|string|max:15',
        ];
        $request->validate($rules);

        $updateData = [
            'last_name' => $request->last_name,
            'first_name' => $request->first_name,
            'phone' => $request->phone,
        ];

        $user->update($updateData);

        return response()->json([
            'message' => 'Cập nhật thông tin thành công!',
            'user' => $user
        ], 200);
    }

    // ===================================================
    // 4. HÀM ĐỔI MẬT KHẨU
    // ===================================================
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed'
        ]);

        if (!Hash::check($request->current_password, $user->password_hash)) {
            return response()->json([
                'message' => 'Mật khẩu hiện tại không chính xác!'
            ], 400);
        }

        $user->update([
            'password_hash' => Hash::make($request->new_password)
        ]);

        return response()->json([
            'message' => 'Đổi mật khẩu thành công!'
        ], 200);
    }
}
