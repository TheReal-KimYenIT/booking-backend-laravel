<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReviewController extends Controller
{
    // Lấy danh sách đánh giá của Khách sạn
    public function index(Request $request)
    {
        try {
            // 1. Xác thực quyền sở hữu khách sạn
            $hotelId = $this->getHotelId();
            if (!$hotelId) {
                return response()->json(['message' => 'Lỗi xác thực người dùng'], 400);
            }

            // Ghi nhận nhật ký hệ thống để phục vụ kiểm tra luồng dữ liệu
            \Illuminate\Support\Facades\Log::info('=== KIỂM TRA: Đối tác đang xem danh sách của hotel_id = ' . $hotelId . ' ===');

            // 2. Thống kê KPI tổng thể cho khách sạn
            $baseStatsQuery = DB::table('reviews')->where('reviews.hotel_id', $hotelId);
            $totalReviews = (clone $baseStatsQuery)->count();
            $avgRating = $totalReviews > 0 ? round((float)(clone $baseStatsQuery)->avg('rating'), 1) : 0;
            $unrepliedCount = (clone $baseStatsQuery)->whereNull('partner_reply')->count();
            $repliedCount = (clone $baseStatsQuery)->whereNotNull('partner_reply')->count();
            $fiveStarCount = (clone $baseStatsQuery)->where('rating', 5)->count();
            $fiveStarRate = $totalReviews > 0 ? round(($fiveStarCount / $totalReviews) * 100) : 0;

            // 3. Xây dựng câu lệnh truy vấn danh sách bài đánh giá
            $query = DB::table('reviews')
                ->leftJoin('customers', 'reviews.customer_id', '=', 'customers.id')
                ->leftJoin('bookings', 'reviews.booking_id', '=', 'bookings.id')
                ->select(
                    'reviews.*',
                    DB::raw("CONCAT(customers.last_name, ' ', customers.first_name) as guest_name"),
                    'customers.phone as guest_phone',
                    'customers.email as guest_email',
                    'bookings.booking_code'
                )
                ->where('reviews.hotel_id', $hotelId)
                ->orderBy('reviews.created_at', 'desc');

            // Áp dụng bộ lọc trạng thái phản hồi từ giao diện
            if ($request->has('status') && $request->status !== 'all') {
                if ($request->status === 'unreplied') {
                    $query->whereNull('reviews.partner_reply');
                } elseif ($request->status === 'replied') {
                    $query->whereNotNull('reviews.partner_reply');
                }
            }

            // Áp dụng bộ lọc số sao (5, 4, 3, 2, 1)
            if ($request->filled('rating') && $request->rating !== 'all') {
                $query->where('reviews.rating', intval($request->rating));
            }

            // Áp dụng tìm kiếm từ khóa (mã đơn, bình luận, tên khách)
            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->where(function($q) use ($search) {
                    $q->where('bookings.booking_code', 'like', "%{$search}%")
                      ->orWhere('reviews.comment', 'like', "%{$search}%")
                      ->orWhere(DB::raw("CONCAT(customers.last_name, ' ', customers.first_name)"), 'like', "%{$search}%");
                });
            }

            // Thực hiện phân trang (Mỗi trang hiển thị 9 thẻ để cân đối giao diện grid 3 cột)
            $paginator = $query->paginate(9);

            // 4. Xử lý đính kèm mảng hình ảnh thực tế từ khách hàng
            $reviewIds = collect($paginator->items())->pluck('id')->toArray();

            if (!empty($reviewIds)) {
                $images = DB::table('review_images')
                    ->whereIn('review_id', $reviewIds)
                    ->get()
                    ->groupBy('review_id');

                foreach ($paginator->items() as $item) {
                    if (isset($images[$item->id])) {
                        $item->review_images = collect($images[$item->id])->map(function ($img) {
                            return [
                                'id' => $img->id,
                                'review_id' => $img->review_id,
                                'image_url' => asset($img->image_url)
                            ];
                        })->all();
                    } else {
                        $item->review_images = [];
                    }
                }
            }

            // 5. Trả kết quả chuẩn hóa kèm thống kê tổng thể
            return response()->json([
                'message' => 'Thành công',
                'data' => $paginator,
                'stats' => [
                    'total_reviews' => $totalReviews,
                    'avg_rating' => $avgRating,
                    'unreplied_count' => $unrepliedCount,
                    'replied_count' => $repliedCount,
                    'five_star_count' => $fiveStarCount,
                    'five_star_rate' => $fiveStarRate
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Lỗi máy chủ hệ thống: ' . $e->getMessage()], 500);
        }
    }

    // Khách sạn phản hồi đánh giá
    public function reply(Request $request, int $id)
    {
        try {
            $hotelId = $this->getHotelId();

            $request->validate([
                'reply_content' => 'required|string|max:1000'
            ]);

            $review = DB::table('reviews')
                ->where('id', $id)
                ->where('hotel_id', $hotelId) // Bảo mật: Đảm bảo chỉ trả lời đánh giá của KS mình
                ->first();

            if (!$review) {
                return response()->json(['message' => 'Không tìm thấy đánh giá'], 404);
            }

            DB::table('reviews')->where('id', $id)->update([
                'partner_reply' => $request->reply_content,
                'replied_at' => Carbon::now()
            ]);

            return response()->json(['message' => 'Đã gửi phản hồi thành công'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Lỗi máy chủ: ' . $e->getMessage()], 500);
        }
    }
}
