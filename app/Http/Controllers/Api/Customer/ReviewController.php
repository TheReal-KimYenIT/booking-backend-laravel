<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Review;
use App\Models\ReviewImage;
// use App\Models\Booking; // Bỏ comment dòng này nếu bạn có Model Booking
use Illuminate\Support\Facades\Storage;

class ReviewController extends Controller
{
    // Khách hàng gửi đánh giá cho một đơn đặt phòng và upload ảnh kèm theo.
    public function store(Request $request)
    {
        // 1. Validate dữ liệu đầu vào
        $request->validate([
            'booking_id' => 'required|integer',
            'hotel_id' => 'required|integer',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'images' => 'nullable|array|max:5', // Chống Spam: Tối đa 5 ảnh mỗi lượt đánh giá
            'images.*' => 'image|mimes:jpeg,png,jpg,webp|max:5120' // Tối đa 5MB/ảnh, hỗ trợ thêm WebP
        ]);

        try {
            $customerId = auth('customer')->id();

            // Kiểm tra xem Booking này có thuộc về khách hàng này và đã hoàn thành chưa
            $booking = \App\Models\Booking::where('id', $request->booking_id)
                ->where('customer_id', $customerId)
                ->first();

            if (!$booking) {
                return response()->json(['status' => 'error', 'message' => 'Đơn đặt phòng không tồn tại hoặc không thuộc về bạn.'], 403);
            }

            if ($booking->status != 3) {
                return response()->json(['status' => 'error', 'message' => 'Bạn chỉ có thể đánh giá sau khi đã trả phòng thành công.'], 403);
            }

            // Kiểm tra xem khách đã đánh giá đơn hàng này chưa (Tránh 1 đơn đánh giá 2 lần)
            $exists = Review::where('booking_id', $request->booking_id)->exists();
            if ($exists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bạn đã gửi đánh giá cho đơn đặt phòng này rồi.'
                ], 400);
            }

            // 2. Lưu thông tin Review vào DB
            $review = Review::create([
                'booking_id' => $request->booking_id,
                'hotel_id' => $request->hotel_id,
                'customer_id' => $customerId,
                'rating' => $request->rating,
                'comment' => $request->comment,
                'status' => 1 // 1: Hiển thị ngay (Nếu muốn Admin duyệt trước thì set là 0)
            ]);

            // 3. Xử lý Upload nhiều ảnh
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $file) {
                    // Lưu ảnh vào thư mục storage/app/public/reviews
                    $path = $file->store('reviews', 'public');

                    // Lưu vào DB bảng review_images
                    ReviewImage::create([
                        'review_id' => $review->id,
                        'image_url' => '/storage/' . $path
                    ]);
                }
            }

            // 4. CẬP NHẬT ĐIỂM ĐÁNH GIÁ TRUNG BÌNH CHO KHÁCH SẠN
            $hotel = \App\Models\Hotel::find($request->hotel_id);
            if ($hotel) {
                $stats = Review::where('hotel_id', $hotel->id)
                    ->where('status', 1)
                    ->selectRaw('AVG(rating) as avg, COUNT(id) as count')
                    ->first();
                    
                $hotel->update([
                    'average_rating' => round($stats->avg ?? 0, 2),
                    'review_count' => $stats->count ?? 0
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Cảm ơn bạn đã gửi đánh giá!'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Lỗi hệ thống: ' . $e->getMessage()
            ], 500);
        }
    }
}
