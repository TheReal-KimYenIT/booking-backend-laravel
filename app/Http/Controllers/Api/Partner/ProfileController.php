<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    // Lấy thông tin tài khoản đối tác đang đăng nhập.
    public function getProfile()
    {
        /** @var \App\Models\Partner $partner */
        $partner = Auth::guard('partner')->user();

        if (!$partner) {
            return response()->json(['message' => 'Không tìm thấy thông tin tài khoản'], 404);
        }

        $partner->load('role');

        if ($partner->parent_id) {
            $owner = \App\Models\Partner::with('hotel')->find($partner->parent_id);
            $partner->hotel = $owner ? $owner->hotel : null;
        } else {
            $partner->load('hotel');
        }

        return response()->json([
            'message' => 'Lấy thông tin thành công',
            'data' => $partner
        ], 200);
    }

    // Cập nhật thông tin cơ bản như tên và số điện thoại.
    public function updateProfile(Request $request)
    {
        /** @var \App\Models\Partner $partner */
        $partner = Auth::guard('partner')->user();

        $request->validate([
            'first_name' => 'required|string|max:50',
            'last_name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:15|regex:/^([0-9\s\-\+\(\)]*)$/',
        ], [
            'first_name.required' => 'Vui lòng nhập tên của bạn.',
            'last_name.required' => 'Vui lòng nhập họ và tên đệm của bạn.',
            'phone.regex' => 'Số điện thoại không đúng định dạng.'
        ]);

        $partner->update([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'phone' => $request->phone,
        ]);

        $partner->load('role');
        if ($partner->parent_id) {
            $owner = \App\Models\Partner::with('hotel')->find($partner->parent_id);
            $partner->hotel = $owner ? $owner->hotel : null;
        } else {
            $partner->load('hotel');
        }

        return response()->json([
            'message' => 'Cập nhật hồ sơ tài khoản thành công!',
            'data' => $partner
        ], 200);
    }

    // Đổi mật khẩu cho tài khoản đối tác.
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/'], // 'confirmed' bắt buộc phải có trường 'new_password_confirmation' gửi lên
        ], [
            'new_password.min' => 'Mật khẩu mới phải có ít nhất 8 ký tự.',
            'new_password.confirmed' => 'Xác nhận mật khẩu mới không khớp.',
            'new_password.regex' => 'Mật khẩu mới phải chứa ít nhất 1 chữ hoa, 1 chữ thường và 1 số.'
        ]);

        /** @var \App\Models\Partner $partner */
        $partner = Auth::guard('partner')->user();

        // Kiểm tra mật khẩu cũ (Chú ý: So sánh với cột password_hash)
        if (!Hash::check($request->current_password, $partner->password_hash)) {
            return response()->json([
                'message' => 'Mật khẩu hiện tại không chính xác!'
            ], 400);
        }

        // Kiểm tra mật khẩu mới không được trùng mật khẩu cũ
        if (Hash::check($request->new_password, $partner->password_hash)) {
            return response()->json([
                'message' => 'Mật khẩu mới không được giống mật khẩu hiện tại!'
            ], 400);
        }

        // Cập nhật mật khẩu mới
        $partner->password_hash = Hash::make($request->new_password);
        $partner->save();

        return response()->json([
            'message' => 'Đổi mật khẩu thành công! Vui lòng sử dụng mật khẩu mới cho lần đăng nhập sau.'
        ], 200);
    }
}
