<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // Lấy thông tin tài khoản khách hàng đang đăng nhập.
    public function getProfile(Request $request)
    {
        return response()->json([
            'message' => 'Lấy thông tin thành công',
            'user' => $request->user()
        ], 200);
    }

    // Cập nhật thông tin cá nhân của khách hàng.
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $rules = [
            'last_name' => 'required|string|max:100',
            'first_name' => 'required|string|max:50',
            'phone' => 'nullable|string|max:15',
            'gender' => 'nullable|string|max:10',
            'dob' => 'nullable|date',
            'address' => 'nullable|string|max:255'
        ];

        $request->validate($rules);

        $updateData = [
            'last_name' => $request->last_name,
            'first_name' => $request->first_name,
            'phone' => $request->phone,
        ];

        // Dùng keys() để kiểm tra xem request có gửi key này lên không, kể cả khi giá trị là null
        if (in_array('gender', $request->keys())) $updateData['gender'] = $request->gender;
        if (in_array('dob', $request->keys())) $updateData['dob'] = $request->dob;
        if (in_array('address', $request->keys())) $updateData['address'] = $request->address;

        $user->update($updateData);

        return response()->json([
            'message' => 'Cập nhật thông tin thành công!',
            'user' => $user
        ], 200);
    }

    // Vô hiệu hóa tài khoản khách hàng.
    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        if ($user instanceof Admin) {
            return response()->json([
                'message' => 'Không thể xóa tài khoản Quản trị viên hệ thống!'
            ], 403);
        }

        $user->update(['is_active' => 0]);
        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Tài khoản đã được xóa thành công!'], 200);
    }

    // Đổi mật khẩu cho tài khoản khách hàng.
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required|string',
            'new_password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/']
        ], [
            'new_password.min' => 'Mật khẩu mới phải có ít nhất 8 ký tự.',
            'new_password.regex' => 'Mật khẩu mới phải chứa ít nhất 1 chữ hoa, 1 chữ thường và 1 số.',
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
