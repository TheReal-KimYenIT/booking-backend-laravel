<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Hotel;
use App\Models\ChatMessage;
use App\Models\Customer;

class ChatThread extends Model
{
    // Tắt tự động timestamps vì bảng của bạn không có updated_at
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'hotel_id',
        'status',
        'created_at'
    ];

    // . Quan hệ với Khách hàng (Lấy tên người nhắn)
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    //  3. Quan hệ với Khách sạn
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }

    //  4. Quan hệ với tất cả tin nhắn trong hội thoại này
    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'thread_id');
    }

    //  5. Lấy tin nhắn mới nhất (Để hiển thị ở list bên trái)
    public function latestMessage()
    {
        return $this->hasOne(ChatMessage::class, 'thread_id')->latest('id');
    }
}
