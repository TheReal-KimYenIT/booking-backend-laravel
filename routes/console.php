<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\Room;

// Lệnh mẫu của Laravel
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Services\BookingCleanupService;

// 1. Quét đơn Hủy (Chạy mỗi phút - dọn dẹp đơn chưa thanh toán sau 15 phút)
Schedule::call(function () {
    BookingCleanupService::cleanupExpiredUnpaid(15);
})->everyMinute();

// 2. Quét đơn NO-SHOW (Chạy 22h tối - để kịp bán phòng đêm)
Schedule::call(function () {
    BookingCleanupService::cleanupNoShow();
})->dailyAt('22:00');
