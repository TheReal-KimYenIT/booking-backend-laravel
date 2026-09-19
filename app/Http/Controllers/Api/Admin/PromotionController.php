<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PromotionController extends Controller
{
    // Lấy danh sách mã khuyến mãi toàn sàn do admin tạo.
    public function index()
    {
        // Lấy các mã do Admin tạo
        $promotions = Promotion::whereNull('hotel_id')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'message' => 'Lấy danh sách khuyến mãi toàn sàn thành công',
            'promotions' => $promotions
        ], 200);
    }

    // Tạo mã khuyến mãi mới cho toàn hệ thống.
    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:50|unique:promotions,code',
            'discount_type' => 'required|integer|in:1,2',
            'discount_value' => 'required|numeric|min:0' . ($request->discount_type == 1 ? '|max:100' : ''),
            'max_discount_amount' => 'nullable|numeric|min:0',
            'min_booking_value' => 'nullable|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'usage_limit' => 'nullable|integer|min:1',
            'status' => 'nullable|integer|in:0,1'
        ], [
            'code.required' => 'Vui lòng nhập mã khuyến mãi!',
            'code.unique' => 'Mã khuyến mãi này đã tồn tại trên hệ thống!',
            'discount_value.max' => 'Mức giảm phần trăm không được vượt quá 100%!',
            'end_date.after_or_equal' => 'Thời gian kết thúc phải diễn ra sau hoặc bằng thời gian bắt đầu!'
        ]);

        $promotion = Promotion::create([
            'hotel_id' => null, // ĐIỂM QUAN TRỌNG NHẤT: Bằng null nghĩa là mã Toàn Sàn
            'code' => strtoupper(trim($request->code)),
            'discount_type' => $request->discount_type,
            'discount_value' => $request->discount_value,
            'max_discount_amount' => $request->max_discount_amount,
            'min_booking_value' => $request->min_booking_value ?? 0,
            'start_date' => date('Y-m-d H:i:s', strtotime($request->start_date)),
            'end_date' => date('Y-m-d H:i:s', strtotime($request->end_date)),
            'usage_limit' => $request->usage_limit,
            'usage_limit_per_user' =>  $request->usage_limit_per_user ?? 1,
            'status' => $request->status ?? 1,
        ]);

        return response()->json(['message' => 'Tạo mã khuyến mãi toàn sàn thành công!', 'promotion' => $promotion], 201);
    }

    // Cập nhật thông tin hoặc trạng thái của mã khuyến mãi.
    public function update(Request $request, int $id)
    {
        $promotion = Promotion::whereNull('hotel_id')->where('id', $id)->first();
        if (!$promotion) return response()->json(['message' => 'Không tìm thấy khuyến mãi'], 404);

        if ($request->has('code')) {
            $request->validate([
                'code' => 'required|string|max:50|unique:promotions,code,' . $id,
                'discount_type' => 'required|integer|in:1,2',
                'discount_value' => 'required|numeric|min:0' . ($request->discount_type == 1 ? '|max:100' : ''),
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ], [
                'code.required' => 'Vui lòng nhập mã khuyến mãi!',
                'code.unique' => 'Mã khuyến mãi này đã tồn tại trên hệ thống!',
                'discount_value.max' => 'Mức giảm phần trăm không được vượt quá 100%!',
                'end_date.after_or_equal' => 'Thời gian kết thúc phải diễn ra sau hoặc bằng thời gian bắt đầu!'
            ]);

            if ($request->filled('usage_limit') && (int)$request->usage_limit < (int)$promotion->used_count) {
                return response()->json([
                    'message' => "Tổng lượt sử dụng ({$request->usage_limit}) không thể nhỏ hơn số lượt đã sử dụng thực tế ({$promotion->used_count})!"
                ], 422);
            }

            $promotion->update([
                'code' => strtoupper(trim($request->code)),
                'discount_type' => $request->discount_type,
                'discount_value' => $request->discount_value,
                'max_discount_amount' => $request->max_discount_amount,
                'min_booking_value' => $request->min_booking_value ?? 0,
                'start_date' => date('Y-m-d H:i:s', strtotime($request->start_date)),
                'end_date' => date('Y-m-d H:i:s', strtotime($request->end_date)),
                'usage_limit' => $request->usage_limit,
                'usage_limit_per_user' =>  $request->usage_limit_per_user ?? 1,
                'status' => $request->status ?? 1,
            ]);
        } else if ($request->has('status')) {
            $promotion->update(['status' => (int)$request->status]);
        }

        return response()->json(['message' => 'Cập nhật khuyến mãi thành công!', 'promotion' => $promotion], 200);
    }

    // Xóa mã khuyến mãi (chỉ cho phép nếu chưa phát sinh lượt đặt phòng)
    public function destroy(int $id)
    {
        $promotion = Promotion::whereNull('hotel_id')->where('id', $id)->first();
        if (!$promotion) return response()->json(['message' => 'Không tìm thấy khuyến mãi'], 404);

        $hasBookings = \App\Models\Booking::where('promotion_id', $id)
            ->orWhere('hotel_promotion_id', $id)
            ->exists();

        if ($hasBookings || (int)$promotion->used_count > 0) {
            return response()->json([
                'message' => "Không thể xóa! Mã này đã được khách hàng sử dụng cho {$promotion->used_count} lượt đặt phòng thực tế."
            ], 400);
        }

        $promotion->delete();
        return response()->json(['message' => 'Xóa mã khuyến mãi thành công!'], 200);
    }
}
