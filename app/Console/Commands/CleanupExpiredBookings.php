<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BookingCleanupService;

class CleanupExpiredBookings extends Command
{
    /**
     * Tên và chữ ký dòng lệnh.
     *
     * @var string
     */
    protected $signature = 'booking:cleanup-expired {--minutes=15 : Số phút chờ tối đa trước khi hủy}';

    /**
     * Mô tả lệnh.
     *
     * @var string
     */
    protected $description = 'Dọn dẹp các đơn đặt phòng quá hạn thanh toán cọc và hoàn trả kho phòng, voucher';

    /**
     * Thực thi lệnh.
     */
    public function handle()
    {
        $minutes = (int)$this->option('minutes');
        $this->info("Đang quét dọn các đơn chờ thanh toán quá {$minutes} phút...");

        $cancelledCount = BookingCleanupService::cleanupExpiredUnpaid($minutes);
        $this->info("Đã tự động hủy và hoàn trả kho phòng cho {$cancelledCount} đơn quá hạn thanh toán.");

        return Command::SUCCESS;
    }
}
