<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ChatThread;
use App\Models\ChatMessage;

class ChatController extends Controller
{
    // Tìm hoặc tạo mới một cuộc trò chuyện giữa khách hàng và khách sạn.
    private function findOrCreateThread(int $hotelId)
    {
        try {
            $customerId = Auth::id();

            // 1. Tìm xem 2 người này đã từng chat chưa
            $thread = ChatThread::where('customer_id', $customerId)
                ->where('hotel_id', $hotelId)
                ->first();

            // 2. Nếu chưa có thì tạo mới (Bơm đủ các trường để tránh lỗi SQL)
            if (!$thread) {
                $thread = ChatThread::create([
                    'customer_id' => $customerId,
                    'hotel_id' => $hotelId,
                    'status' => 0
                ]);
            }

            // 3. Trả về dữ liệu
            return response()->json([
                'data' => $thread->load(['messages' => function ($query) {
                    $query->orderBy('created_at', 'asc');
                }])
            ], 200);
        } catch (\Exception $e) {
            // Trả về lỗi chi tiết
            return response()->json([
                'message' => 'Lỗi tạo phòng chat: ' . $e->getMessage()
            ], 500);
        }
    }

    // Mở chat trước khi khách hàng đặt phòng.
    public function getPreBookingChat(int $hotelId)
    {
        return $this->findOrCreateThread($hotelId);
    }

    // Mở chat liên quan đến một đơn đặt phòng cụ thể.
    public function index(int $bookingId)
    {
        $booking = \App\Models\Booking::findOrFail($bookingId);
        return $this->findOrCreateThread($booking->hotel_id);
    }

    // Gửi tin nhắn mới vào một cuộc trò chuyện.
    public function store(Request $request, int $threadId)
    {
        $request->validate(['message' => 'required|string']);

        $msg = ChatMessage::create([
            'thread_id' => $threadId,
            'sender_id' => Auth::id(),
            'sender_type' => 'customer',
            'message' => $request->message,
            'created_at' => now()
        ]);

        // Đánh dấu hội thoại là chưa đọc (0) đối với phía đối tác
        ChatThread::where('id', $threadId)->update(['status' => 0]);

        return response()->json($msg, 201);
    }

    // Lấy danh sách các cuộc chat của khách hàng hiện tại.
    public function getAllThreads()
    {
        $threads = ChatThread::where('customer_id', Auth::id())
            ->with([
                'hotel' => function ($q) {
                    $q->select('id', 'name', 'address', 'city', 'star_rating')->with('images');
                },
                'latestMessage'
            ])
            ->get()
            ->sortByDesc(function ($thread) {
                return $thread->latestMessage ? $thread->latestMessage->created_at : $thread->created_at;
            })
            ->values();

        return response()->json(['data' => $threads]);
    }
}
