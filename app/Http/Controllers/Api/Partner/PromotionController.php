<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PromotionController extends Controller
{
    // 1. Xem danh sách khuyến mãi (index)
    public function index(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        // Lấy toàn bộ mã khuyến mãi của Khách sạn này (Bao gồm cả Hết hạn và Còn hạn)
        $promotions = Promotion::where('hotel_id', $hotelId)
            ->orderBy('created_at', 'desc')
            ->get();

        $now = Carbon::now();

        // Gắn thêm cờ is_expired để Angular biết mã nào đã quá hạn và tô màu đỏ
        $promotions->map(function ($promo) use ($now) {
            $promo->is_expired = ($now > Carbon::parse($promo->end_date));
            return $promo;
        });

        return response()->json([
            'message' => 'Lấy danh sách khuyến mãi thành công',
            'data' => $promotions,       // Trả về key 'data'
            'promotions' => $promotions  // Trả về thêm key 'promotions' để phòng hờ Angular gọi nhầm
        ], 200);
    }

    // 2. Tạo mã khuyến mãi mới (store)
    public function store(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        $request->validate([
            'code' => 'required|string|max:50|unique:promotions,code',
            'discount_type' => 'required|integer|in:1,2',
            'discount_value' => 'required|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'min_booking_value' => 'nullable|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'usage_limit' => 'nullable|integer|min:1',
            'usage_limit_per_user' => 'nullable|integer|min:1',
            'status' => 'nullable|integer|in:0,1'
        ]);

        $promotion = Promotion::create([
            'hotel_id' => $hotelId,
            'code' => strtoupper($request->code),
            'discount_type' => $request->discount_type,
            'discount_value' => $request->discount_value,
            'max_discount_amount' => $request->max_discount_amount,
            'min_booking_value' => $request->min_booking_value ?? 0,
            'start_date' => date('Y-m-d H:i:s', strtotime($request->start_date)),
            'end_date' => date('Y-m-d H:i:s', strtotime($request->end_date)),
            'usage_limit' => $request->usage_limit,
            'usage_limit_per_user' => $request->usage_limit_per_user ?? 1,
            'status' => $request->status ?? 1,
        ]);

        return response()->json(['message' => 'Tạo khuyến mãi thành công!', 'promotion' => $promotion], 201);
    }

    // 3. Cập nhật khuyến mãi (update)
    public function update(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();
        $promotion = Promotion::where('id', $id)->where('hotel_id', $hotelId)->first();

        if (!$promotion) return response()->json(['message' => 'Không tìm thấy khuyến mãi'], 404);

        if ($request->has('code')) {
            $request->validate([
                'discount_type' => 'required|integer|in:1,2',
                'discount_value' => 'required|numeric|min:0',
                'max_discount_amount' => 'nullable|numeric|min:0',
                'min_booking_value' => 'nullable|numeric|min:0',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'usage_limit' => 'nullable|integer|min:1',
                'usage_limit_per_user' => 'nullable|integer|min:1',
                'status' => 'nullable|integer|in:0,1'
            ]);

            $promotion->update([
                'discount_type' => $request->discount_type,
                'discount_value' => $request->discount_value,
                'max_discount_amount' => $request->max_discount_amount,
                'min_booking_value' => $request->min_booking_value ?? 0,
                'start_date' => date('Y-m-d H:i:s', strtotime($request->start_date)),
                'end_date' => date('Y-m-d H:i:s', strtotime($request->end_date)),
                'usage_limit' => $request->usage_limit,
                'usage_limit_per_user' => $request->usage_limit_per_user ?? 1,
                'status' => $request->status ?? 1,
            ]);
        } else if ($request->has('status')) {
            $promotion->update(['status' => $request->status]);
        }

        return response()->json(['message' => 'Cập nhật khuyến mãi thành công', 'promotion' => $promotion], 200);
    }

    // 4. Kết thúc sớm khuyến mãi (endEarly)
    public function endEarly(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();
        $promotion = Promotion::where('id', $id)->where('hotel_id', $hotelId)->first();

        if (!$promotion) return response()->json(['message' => 'Không tìm thấy khuyến mãi'], 404);

        $promotion->update([
            'status' => 0,
            'end_date' => Carbon::today()->toDateString()
        ]);

        return response()->json(['message' => 'Đã kết thúc sớm khuyến mãi', 'promotion' => $promotion], 200);
    }

    // 5. Xem thống kê (stats)
    public function stats(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();
        $promotion = Promotion::where('id', $id)->where('hotel_id', $hotelId)->first();

        if (!$promotion) return response()->json(['message' => 'Không tìm thấy khuyến mãi'], 404);

        return response()->json([
            'message' => 'Lấy thống kê thành công',
            'promotion_code' => $promotion->code,
            'total_used' => $promotion->used_count
        ], 200);
    }
}
