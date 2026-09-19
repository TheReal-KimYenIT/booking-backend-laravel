<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Enums\BookingStatus;

class Booking extends Model
{
    // Bỏ dòng 'public $timestamps = false;' để Laravel tự quản lý created_at, updated_at
    // Bảng của bạn đã có 2 cột này ở vị trí số 17 và 26 rồi.

    protected $fillable = [
        'booking_code',
        'customer_id',
        'hotel_id',
        'promotion_id',
        'hotel_promotion_id',
        'guest_name',
        'guest_phone',
        'guest_email',       // <-- Mới thêm hôm nay
        'note',              // <-- Mới thêm hôm nay
        'special_requests',

        // --- Phần Giá tiền ---
        'total_amount',
        'deposit_amount',
        'discount_amount',
        'vat_amount',
        'vat_rate',
        'commission_rate',
        'platform_fee',

        // Chính sách hủy phòng (Snapshot)
        'free_cancel_hours',
        'partial_refund_hours',
        'partial_refund_percent',

        //hoàn tiền
        'refund_status',
        'refund_amount',
        'refund_bank',
        'refund_account',
        'refund_account_name',
        'refund_receipt_url',

        // --- Phần Trạng thái ---
        'status',
        'payment_status',    // <-- THÊM VÀO: Đã có trong DB
        'cancellation_reason',

        // --- Phần Thời gian ---
        'check_in',
        'check_out',
        'estimated_arrival_time',
        'estimated_departure_time',
        'actual_check_in_at',  // <-- THÊM VÀO: Đã có trong DB
        'actual_check_out_at', // <-- THÊM VÀO: Đã có trong DB

    ];

    protected $casts = [
        // 'status' => BookingStatus::class, // Tạm khóa nếu bạn chưa có file Enum này, nếu có rồi thì mở ra
        'actual_check_in_at' => 'datetime',
        'actual_check_out_at' => 'datetime',
    ];

    // ==========================================
    // CÁC LIÊN KẾT (RELATIONSHIPS)
    // ==========================================

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }

    public function details()
    {
        return $this->hasMany(BookingDetail::class, 'booking_id');
    }

    public function payment()
    {
        return $this->hasOne(Payment::class, 'booking_id');
    }

    // ĐÃ XÓA hàm roomType() vì booking không liên kết trực tiếp với room_type nữa.
    // Phải gọi qua: $booking->details->first()->roomType

    public function bookingServices()
    {
        return $this->hasMany(BookingService::class);
    }

    public function bookingMinibars()
    {
        return $this->hasMany(BookingMinibar::class);
    }


    // Bổ sung quan hệ lấy danh sách phòng vật lý đã gán
    public function roomAssignments()
    {
        return $this->hasMany(BookingRoomAssignment::class, 'booking_id');
    }

    // Bổ sung quan hệ lấy danh sách khách lưu trú
    public function guests()
    {
        return $this->hasMany(BookingGuest::class, 'booking_id');
    }

    public function bookingSurcharges()
    {
        return $this->hasMany(\App\Models\BookingSurcharge::class, 'booking_id');
    }

    // Bổ sung mối quan hệ lấy danh sách phụ thu
    public function surcharges()
    {
        return $this->hasMany(BookingSurcharge::class, 'booking_id')->with('category');
    }

    public function supply_incidents()
    {
        return $this->hasMany(SupplyIncident::class, 'booking_id');
    }
    // (Một đơn đặt phòng chỉ có tối đa 1 bài đánh giá)
    public function review()
    {
        return $this->hasOne(Review::class, 'booking_id', 'id');
    }

    // Bổ sung quan hệ lấy thông tin mã khuyến mãi của Sàn
    public function promotion()
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }

    // Bổ sung quan hệ lấy thông tin mã khuyến mãi của Khách sạn
    public function hotelPromotion()
    {
        return $this->belongsTo(Promotion::class, 'hotel_promotion_id');
    }
}
