<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\RoomInventory;
use App\Models\Promotion;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class BookingCleanupService
{
    /**
     * Dọn dẹp đơn quá hạn thanh toán cọc (> 15 phút).
     * Chuyển status = 4 (Đã hủy), hoàn trả số lượng kho phòng và lượt dùng khuyến mãi.
     */
    public static function cleanupExpiredUnpaid(int $minutes = 15): int
    {
        $threshold = Carbon::now()->subMinutes($minutes);

        $expiredBookings = Booking::where('status', 0)
            ->where('payment_status', 0)
            ->where('created_at', '<', $threshold)
            ->get();

        $count = 0;
        foreach ($expiredBookings as $booking) {
            DB::transaction(function () use ($booking) {
                // 1. Hoàn trả số lượng phòng vào RoomInventory cho từng ngày
                $checkInDate = Carbon::parse($booking->check_in);
                $checkOutDate = Carbon::parse($booking->check_out);
                $bookingDetails = BookingDetail::where('booking_id', $booking->id)->get();

                foreach ($bookingDetails as $detail) {
                    for ($currentDate = $checkInDate->copy(); $currentDate->lt($checkOutDate); $currentDate->addDay()) {
                        $dateStr = $currentDate->format('Y-m-d');
                        $inventory = RoomInventory::where('room_type_id', $detail->room_type_id)
                            ->where('apply_date', $dateStr)
                            ->first();

                        if ($inventory) {
                            $inventory->increment('available_allotment', $detail->rooms_count);
                        }
                    }
                }

                // 2. Hoàn trả lượt dùng mã khuyến mãi
                if ($booking->promotion_id) {
                    Promotion::where('id', $booking->promotion_id)
                        ->where('used_count', '>', 0)
                        ->decrement('used_count');
                }
                if ($booking->hotel_promotion_id) {
                    Promotion::where('id', $booking->hotel_promotion_id)
                        ->where('used_count', '>', 0)
                        ->decrement('used_count');
                }

                // 3. Xóa hoàn toàn khỏi database (vì chưa từng thanh toán, là đơn bỏ dở)
                $booking->delete();
            });
            $count++;
        }

        // Dọn dẹp cả những đơn test chưa thanh toán đã bị đánh dấu hủy quá 15 phút trước đây
        $staleCount = Booking::where('payment_status', 0)
            ->where('status', 4)
            ->where('cancellation_reason', 'LIKE', '%15 phút%')
            ->delete();
        $count += $staleCount;

        return $count;
    }

    /**
     * Dọn dẹp đơn No-Show: Quá ngày check-in mà khách không đến nhận phòng (status = 1).
     */
    public static function cleanupNoShow(): int
    {
        $today = Carbon::today()->toDateString();

        $noShowBookings = Booking::where('status', 1)
            ->where('check_in', '<', $today)
            ->get();

        $count = 0;
        foreach ($noShowBookings as $booking) {
            DB::transaction(function () use ($booking) {
                // Nhả trạng thái phòng vật lý nếu đã gán phòng
                $roomIds = $booking->roomAssignments ? $booking->roomAssignments->pluck('room_id') : collect([]);
                if ($roomIds->isNotEmpty()) {
                    Room::whereIn('id', $roomIds)->update(['status' => 1]); // 1: Sẵn sàng đón khách
                }

                $booking->update([
                    'status' => 5, // Khách không đến
                    'cancellation_reason' => 'Hệ thống đánh dấu: Khách không đến nhận phòng (No-Show)',
                ]);

                // Trả lại kho phòng cho các ngày sau ngày check-in nếu có
                $checkInDate = Carbon::parse($booking->check_in);
                $checkOutDate = Carbon::parse($booking->check_out);
                $bookingDetails = BookingDetail::where('booking_id', $booking->id)->get();

                foreach ($bookingDetails as $detail) {
                    for ($currentDate = $checkInDate->copy(); $currentDate->lt($checkOutDate); $currentDate->addDay()) {
                        $dateStr = $currentDate->format('Y-m-d');
                        $inventory = RoomInventory::where('room_type_id', $detail->room_type_id)
                            ->where('apply_date', $dateStr)
                            ->first();
                        if ($inventory) {
                            $inventory->increment('available_allotment', $detail->rooms_count);
                        }
                    }
                }
            });
            $count++;
        }

        return $count;
    }

    /**
     * Tự động quét nhanh khi có request (Lazy Cleanup / On-the-fly).
     * Dùng Cache giới hạn tần suất (tối đa 1 lần / 60 giây) để đạt hiệu năng tối đa.
     */
    public static function autoCleanupIfNeeded(): void
    {
        $cacheKey = 'last_booking_auto_cleanup_timestamp';
        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, now()->timestamp, 60);

        try {
            self::cleanupExpiredUnpaid(15);
        } catch (\Throwable $e) {
            Log::error('Auto cleanup expired bookings error: ' . $e->getMessage());
        }
    }
}
