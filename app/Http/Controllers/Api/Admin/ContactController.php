<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    // Lấy danh sách các liên hệ gửi từ người dùng cho admin xem.
    public function index()
    {
        // Lấy danh sách, sắp xếp tin nhắn mới nhất lên đầu
        $contacts = Contact::orderBy('created_at', 'desc')->get();

        return response()->json(['data' => $contacts]);
    }

    // Đánh dấu một liên hệ đã được xử lý.
    public function resolve(int $id)
    {
        $contact = Contact::findOrFail($id);

        $contact->update([
            'status' => 1
        ]);

        return response()->json(['message' => 'Đã đánh dấu xử lý thành công!']);
    }

    // Cập nhật trạng thái liên hệ (0: Chưa xử lý, 1: Đã giải quyết)
    public function updateStatus(Request $request, int $id)
    {
        $contact = Contact::findOrFail($id);
        $status = $request->input('status', 1);

        $contact->update([
            'status' => (int)$status
        ]);

        return response()->json(['message' => 'Cập nhật trạng thái liên hệ thành công!', 'data' => $contact]);
    }

    // Xóa liên hệ
    public function destroy(int $id)
    {
        $contact = Contact::findOrFail($id);
        $contact->delete();

        return response()->json(['message' => 'Đã xóa tin nhắn liên hệ thành công!']);
    }
}
