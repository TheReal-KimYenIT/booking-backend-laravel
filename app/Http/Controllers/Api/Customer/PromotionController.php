<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PromotionController extends Controller
{
    // Lấy các mã khuyến mãi đang hoạt động để khách hàng xem.
    public function getActivePromotions()
    {
        $now = Carbon::now();

        // Lấy các mã có status = 1, đang trong thời gian áp dụng, và chưa hết lượt
        $promotions = Promotion::with('hotel:id,name') // Lấy kèm tên khách sạn (nếu có)
            ->where('status', 1)
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now)
            ->where(function ($query) {
                $query->whereNull('usage_limit')
                    ->orWhereColumn('used_count', '<', 'usage_limit');
            })
            ->orderBy('created_at', 'desc')
            ->get();

        // Phân loại: Mã Toàn sàn và Mã Khách sạn
                $customer = auth('customer')->user() ?: request()->user('customer');
        if ($customer) {
            foreach ($promotions as $promo) {
                if ($promo->usage_limit_per_user !== null) {
                    $used = \App\Models\Booking::where('customer_id', $customer->id)
                        ->where(function ($q) use ($promo) {
                            $q->where('promotion_id', $promo->id)->orWhere('hotel_promotion_id', $promo->id);
                        })->count();
                    $promo->is_exhausted = ($used >= $promo->usage_limit_per_user);
                } else {
                    $promo->is_exhausted = false;
                }
            }
        } else {
            foreach ($promotions as $promo) {
                $promo->is_exhausted = false;
            }
        }

        $globalPromos = $promotions->whereNull('hotel_id')->values();
        $hotelPromos = $promotions->whereNotNull('hotel_id')->values();

        return response()->json([
            'message' => 'Lấy danh sách khuyến mãi thành công',
            'data' => [
                'global' => $globalPromos,
                'hotels' => $hotelPromos
            ]
        ], 200);
    }

    // Kiểm tra mã khuyến mãi có hợp lệ và tính số tiền giảm.
    public function checkPromotion(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'hotel_id' => 'required|integer',
            'subtotal' => 'required|numeric'
        ]);

        $customer = auth('customer')->user() ?: $request->user('customer');

        if (!$customer) {
            return response()->json(['message' => 'Vui lòng đăng nhập để áp dụng mã giảm giá.'], 401);
        }

        $promotion = \App\Models\Promotion::where('code', $request->code)
            ->where(function ($query) use ($request) {
                $query->whereNull('hotel_id')->orWhere('hotel_id', $request->hotel_id);
            })->first();

        if (!$promotion) return response()->json(['message' => 'Mã khuyến mãi không tồn tại hoặc không áp dụng cho khách sạn này.'], 400);
        if ($promotion->status !== 1) return response()->json(['message' => 'Mã khuyến mãi đã bị khóa.'], 400);

        $now = \Carbon\Carbon::now();
        if ($now < $promotion->start_date || $now > $promotion->end_date) {
            return response()->json(['message' => 'Mã khuyến mãi đã hết hạn hoặc chưa diễn ra.'], 400);
        }

        if ($promotion->usage_limit !== null && $promotion->used_count >= $promotion->usage_limit) {
            return response()->json(['message' => 'Mã khuyến mãi đã hết lượt sử dụng trên hệ thống.'], 400);
        }

        if ($promotion->min_booking_value > 0 && $request->subtotal < $promotion->min_booking_value) {
            return response()->json(['message' => 'Đơn hàng chưa đạt giá trị tối thiểu (' . number_format($promotion->min_booking_value) . 'đ) để dùng mã.'], 400);
        }

        if ($promotion->usage_limit_per_user !== null) {
            $used = \App\Models\Booking::where('customer_id', $customer->id)
                ->where('promotion_id', $promotion->id)
                ->orWhere('hotel_promotion_id', $promotion->id) // Tìm ở cả 2 cột
                ->count();

            if ($used >= $promotion->usage_limit_per_user) {
                return response()->json(['message' => 'Bạn đã dùng hết số lượt cho phép (' . $promotion->usage_limit_per_user . ' lần) đối với mã này.'], 400);
            }
        }

        $discountAmount = 0;
        if ($promotion->discount_type == 1) { // Giảm %
            $discountAmount = $request->subtotal * ($promotion->discount_value / 100);
            if ($promotion->max_discount_amount !== null && $discountAmount > $promotion->max_discount_amount) {
                $discountAmount = $promotion->max_discount_amount;
            }
        } else { // Giảm tiền mặt
            $discountAmount = $promotion->discount_value;
        }

        if ($discountAmount > $request->subtotal) $discountAmount = $request->subtotal;

        // Phân loại mã để trả về cho React
        $type = is_null($promotion->hotel_id) ? 'global' : 'hotel';

        return response()->json([
            'message' => 'Áp dụng mã thành công!',
            'discount_amount' => $discountAmount,
            'type' => $type, // 'global' hoặc 'hotel'
            'promo_data' => $promotion
        ], 200);
    }
}
