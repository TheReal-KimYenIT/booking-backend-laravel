<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ChatThread;
use App\Models\ChatMessage;
use App\Models\Hotel;

class ChatController extends Controller
{
    public function index()
    {
        try {
            // Lấy ID Khách sạn của đối tác đang đăng nhập
            $hotelId = $this->getHotelId();

            // Nếu đối tác chưa có khách sạn, trả về danh sách rỗng
            if (!$hotelId) {
                return response()->json(['data' => []]);
            }

            // Lọc các đoạn chat thuộc về khách sạn của đối tác này
            $threads = ChatThread::where('hotel_id', $hotelId)
                ->with(['customer', 'messages'])
                ->get();

            $formatted = $threads->map(function ($thread) use ($hotelId) {
                $sortedMessages = $thread->messages->sortBy('created_at')->values();

                $customerName = 'Khách hàng';
                $customerPhone = null;
                $customerEmail = null;
                if ($thread->customer) {
                    $customerName = trim($thread->customer->last_name . ' ' . $thread->customer->first_name);
                    $customerPhone = $thread->customer->phone;
                    $customerEmail = $thread->customer->email;
                }

                // Lấy thông tin đơn đặt phòng mới nhất của khách tại khách sạn này
                $latestBooking = \App\Models\Booking::where('customer_id', $thread->customer_id)
                    ->where('hotel_id', $hotelId)
                    ->latest('id')
                    ->first();

                $lastMessageObj = $sortedMessages->isNotEmpty() ? $sortedMessages->last() : null;

                return [
                    'id' => $thread->id,
                    'customer_id' => $thread->customer_id,
                    'full_name' => $customerName,
                    'phone' => $customerPhone,
                    'email' => $customerEmail,
                    'booking_id' => $latestBooking ? $latestBooking->id : null,
                    'booking_code' => $latestBooking ? $latestBooking->booking_code : null,
                    'message' => $lastMessageObj ? $lastMessageObj->message : 'Chưa có tin nhắn',
                    'created_at' => $lastMessageObj ? $lastMessageObj->created_at : $thread->created_at,
                    'last_sender_type' => $lastMessageObj ? $lastMessageObj->sender_type : null,
                    'status' => $thread->status,
                    'messages' => $sortedMessages
                ];
            });

            // Sắp xếp các thread có tin nhắn mới nhất lên đầu danh sách
            $formatted = $formatted->sortByDesc('created_at')->values();

            return response()->json(['data' => $formatted]);
        } catch (\Exception $e) {
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
            'created_at' => now()
        ]);

        // Cập nhật trạng thái thread thành đã trả lời (1) nếu đang là chưa đọc (0)
        ChatThread::where('id', $threadId)->where('status', 0)->update(['status' => 1]);

        return response()->json($msg, 201);
    }

    public function getMessages(int $threadId)
    {
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
