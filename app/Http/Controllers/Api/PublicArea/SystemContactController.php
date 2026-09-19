<?php

namespace App\Http\Controllers\Api\PublicArea;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\Request;

class SystemContactController extends Controller
{
    // Gửi liên hệ từ trang chủ tới hệ thống quản trị.
    public function store(Request $request)
    {
        // Kiểm tra dữ liệu người dùng gửi lên.
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string',
        ]);

        // Nếu người dùng đã đăng nhập thì lấy id để admin tiện theo dõi.
        $customerId = auth('sanctum')->check() ? auth('sanctum')->id() : null;

        // Lưu liên hệ vào bảng contact để admin xử lý sau.
        Contact::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'subject' => $validated['subject'] ?? 'Liên hệ từ trang chủ',
            'message' => $validated['message'],
            'customer_id' => $customerId, // Lưu lại ID nếu có, không có thì null
            'status' => 0 // 0: Mới gửi, chưa xử lý
        ]);

        return response()->json(['message' => 'Tin nhắn của bạn đã được gửi đến Ban Quản Trị thành công!'], 201);
    }
}
