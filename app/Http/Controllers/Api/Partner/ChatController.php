<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ChatThread;
use App\Models\ChatMessage;
use App\Models\Hotel;
use App\Models\Customer;

class ChatController extends Controller
{
    public function index()
    {
        try {
            $threads = \App\Models\ChatThread::with(['customer', 'booking', 'messages'])
                ->orderBy('id', 'desc')
                ->get();

            $formatted = $threads->map(function ($thread) {
                $sortedMessages = $thread->messages->sortBy('created_at')->values();

                $customerName = 'Khách hàng';
                if ($thread->customer) {
                    $customerName = trim($thread->customer->last_name . ' ' . $thread->customer->first_name);
                }
                return [
                    'id' => $thread->id,
                    'full_name' => $customerName,

                    // 👉 ĐÃ SỬA: Trả về ID số để làm link, và Code chữ để hiển thị
                    'booking_id' => $thread->booking ? $thread->booking->id : null,
                    'booking_code' => $thread->booking ? $thread->booking->booking_code : null,

                    'message' => $sortedMessages->isNotEmpty() ? $sortedMessages->last()->message : 'Chưa có tin nhắn',
                    'created_at' => $sortedMessages->isNotEmpty() ? $sortedMessages->last()->created_at : $thread->created_at,
                    'status' => $thread->status,
                    'messages' => $sortedMessages
                ];
            });
            return response()->json(['data' => $formatted]);
        } catch (\Exception $e) {
            // LỖI 500 SẼ HIỆN RA Ở ĐÂY - CHỤP MÀN HÌNH NẾU VẪN LỖI
            return response()->json(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }

    public function store(Request $request, int $threadId)
    {
        $request->validate(['message' => 'required|string']);

        $msg = ChatMessage::create([
            'thread_id' => $threadId,
            'sender_id' => Auth::id(),
            'sender_type' => 'partner',
            'message' => $request->message,
            'created_at' => now() // Bơm luôn thời gian hiện tại
        ]);

        return response()->json($msg, 201);
    }

    public function getMessages(int $threadId)
    {
        // Lấy tất cả tin nhắn của thread này
        $messages = ChatMessage::where('thread_id', $threadId)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['data' => $messages]);
    }

    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'status' => 'required|integer'
        ]);

        $thread = ChatThread::find($id);
        if ($thread) {
            $thread->status = $request->status;
            $thread->save();
            return response()->json(['message' => 'Cập nhật trạng thái thành công']);
        }

        return response()->json(['message' => 'Không tìm thấy cuộc hội thoại'], 404);
    }
}
