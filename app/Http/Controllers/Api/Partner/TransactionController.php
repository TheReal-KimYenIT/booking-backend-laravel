<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        try {
            // Lấy ID khách sạn của Đối tác đang đăng nhập
            $hotelId = $this->getHotelId();
            if (!$hotelId) {
                return response()->json(['message' => 'Bạn chưa có hồ sơ khách sạn.'], 400);
            }

            $startDate = Carbon::parse($request->query('start_date', '2026-01-01'))->startOfDay();
            $endDate = Carbon::parse($request->query('end_date', Carbon::today()->toDateString()))->endOfDay();

            $hotel = DB::table('hotels')->where('id', $hotelId)->first();
            $defaultCommission = (float)($hotel->commission_rate ?? 15);

            $query = DB::table('payments')
                ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
                ->leftJoin('hotels', 'bookings.hotel_id', '=', 'hotels.id')
                ->where('bookings.hotel_id', $hotelId) // 👉 BẢO MẬT: Chỉ lấy đơn của KS này
                ->whereBetween('payments.created_at', [$startDate, $endDate]);

            if ($request->has('keyword') && !empty($request->query('keyword'))) {
                $kw = trim($request->query('keyword'));
                $query->where(function($q) use ($kw) {
                    $q->where('bookings.booking_code', 'like', "%{$kw}%")
                      ->orWhere('bookings.guest_name', 'like', "%{$kw}%")
                      ->orWhere('bookings.guest_phone', 'like', "%{$kw}%")
                      ->orWhere('payments.transaction_id', 'like', "%{$kw}%");
                });
            }
            if ($request->has('method') && $request->query('method') !== 'all') {
                $query->where('payments.payment_method', $request->query('method'));
            }
            if ($request->has('status') && $request->query('status') !== 'all') {
                $query->where('payments.payment_status', $request->query('status'));
            }

            // --- TÍNH TOÁN THỐNG KÊ TỔNG QUAN BẰNG SQL TRỰC TIẾP TRONG DATABASE (CỰC NHANH & CHÍNH XÁC) ---
            $sqlStats = (clone $query)->selectRaw('
                COALESCE(SUM(CASE WHEN payments.payment_status = 1 THEN payments.amount ELSE 0 END), 0) as grand_total,
                COALESCE(SUM(CASE WHEN payments.payment_status = 1 AND payments.payment_method = 4 THEN payments.amount ELSE 0 END), 0) as vnpay_total,
                COALESCE(SUM(CASE WHEN payments.payment_status = 1 AND payments.payment_method = 1 THEN payments.amount ELSE 0 END), 0) as cash_total,
                COALESCE(SUM(CASE WHEN payments.payment_status = 1 AND payments.payment_method = 3 THEN payments.amount ELSE 0 END), 0) as transfer_total,
                COALESCE(SUM(CASE WHEN payments.payment_status = 1 AND payments.payment_method = 2 THEN payments.amount ELSE 0 END), 0) as pos_total,
                COALESCE(SUM(CASE 
                    WHEN payments.payment_status = 1 THEN 
                        CASE 
                            WHEN payments.amount = bookings.total_amount AND bookings.platform_fee > 0 THEN bookings.platform_fee
                            ELSE payments.amount * (COALESCE(bookings.commission_rate, ' . $defaultCommission . ') / 100)
                        END
                    ELSE 0 
                END), 0) as total_commission,
                COALESCE(SUM(CASE 
                    WHEN payments.payment_status = 1 THEN 
                        CASE 
                            WHEN payments.amount = bookings.total_amount AND bookings.vat_amount > 0 THEN bookings.vat_amount
                            ELSE payments.amount * (COALESCE(bookings.vat_rate, 10) / (100 + COALESCE(bookings.vat_rate, 10)))
                        END
                    ELSE 0 
                END), 0) as total_vat,
                COALESCE(COUNT(CASE WHEN payments.payment_status = 1 THEN 1 END), 0) as success_count,
                COALESCE(COUNT(CASE WHEN payments.payment_status = 2 THEN 1 END), 0) as failed_count,
                COALESCE(COUNT(CASE WHEN payments.payment_status = 0 THEN 1 END), 0) as pending_count
            ')->first();

            $grandTotal = round((float)$sqlStats->grand_total);
            $vnpayTotal = round((float)$sqlStats->vnpay_total);
            $cashTotal = round((float)$sqlStats->cash_total);
            $transferTotal = round((float)$sqlStats->transfer_total);
            $posTotal = round((float)$sqlStats->pos_total);
            $counterTotal = $cashTotal + $transferTotal + $posTotal;
            $totalCommission = round((float)$sqlStats->total_commission);
            $totalPayout = $grandTotal - $totalCommission;
            $totalVat = round((float)$sqlStats->total_vat);
            $totalNetRoom = max(0, $totalPayout - $totalVat);

            $totalSuccessCount = (int)$sqlStats->success_count;
            $totalFailedCount = (int)$sqlStats->failed_count;
            $totalPendingCount = (int)$sqlStats->pending_count;

            // Ngày doanh thu cao nhất & thấp nhất trực tiếp qua SQL
            $dailyStats = (clone $query)
                ->where('payments.payment_status', 1)
                ->selectRaw('DATE(payments.created_at) as pay_date, SUM(payments.amount) as day_total')
                ->groupBy(DB::raw('DATE(payments.created_at)'))
                ->orderByDesc('day_total')
                ->get();

            $peakRevenue = $dailyStats->isNotEmpty() ? round((float)$dailyStats->first()->day_total) : 0;
            $peakDate = $dailyStats->isNotEmpty() ? Carbon::parse($dailyStats->first()->pay_date)->format('d/m') : null;
            $lowestRevenue = $dailyStats->isNotEmpty() ? round((float)$dailyStats->last()->day_total) : 0;
            $lowestDate = $dailyStats->isNotEmpty() ? Carbon::parse($dailyStats->last()->pay_date)->format('d/m') : null;

            // Hỗ trợ giới hạn linh hoạt (ví dụ khi export lấy 5000 dòng)
            $perPage = min(5000, max(1, (int)$request->query('limit', $request->query('per_page', 15))));
            $transactions = (clone $query)->select(
                'payments.*',
                'bookings.booking_code',
                'bookings.guest_name',
                'bookings.guest_phone',
                'bookings.total_amount',
                'bookings.commission_rate as booking_commission_rate',
                'bookings.platform_fee as booking_platform_fee',
                'bookings.status as booking_status',
                'bookings.refund_amount',
                'bookings.vat_amount',
                'bookings.vat_rate',
                'bookings.check_in',
                'bookings.check_out',
                'hotels.name as hotel_name',
                'hotels.id as hotel_id',
                'hotels.commission_rate as hotel_commission_rate',
                DB::raw('COALESCE(bookings.commission_rate, hotels.commission_rate, ' . $defaultCommission . ') as applied_rate'),
                DB::raw('(CASE WHEN payments.amount = bookings.total_amount AND bookings.platform_fee > 0 THEN ROUND(bookings.platform_fee) ELSE ROUND((CASE WHEN payments.amount > COALESCE(bookings.refund_amount, 0) AND bookings.status = 4 AND payments.payment_method = 4 THEN payments.amount - bookings.refund_amount ELSE payments.amount END) * (COALESCE(bookings.commission_rate, hotels.commission_rate, ' . $defaultCommission . ') / 100)) END) as commission_fee'),
                DB::raw('(CASE WHEN payments.amount = bookings.total_amount AND bookings.vat_amount > 0 THEN ROUND(bookings.vat_amount) ELSE ROUND((CASE WHEN payments.amount > COALESCE(bookings.refund_amount, 0) AND bookings.status = 4 AND payments.payment_method = 4 THEN payments.amount - bookings.refund_amount ELSE payments.amount END) * (COALESCE(bookings.vat_rate, 10) / (100 + COALESCE(bookings.vat_rate, 10)))) END) as payment_vat'),
                DB::raw('(CASE WHEN bookings.status = 4 AND payments.payment_method = 4 THEN COALESCE(bookings.refund_amount, 0) ELSE 0 END) as refund_deducted')
            )->orderBy('payments.created_at', 'desc')->paginate($perPage);

            $transactions->getCollection()->transform(function ($item) {
                $amount = (float)$item->amount;
                $fee = (float)$item->commission_fee;
                $vat = (float)$item->payment_vat;
                $refund = (float)$item->refund_deducted;

                $item->applied_rate = (float)$item->applied_rate;
                $item->vat_rate = (float)($item->vat_rate ?? 10);
                $item->refund_deducted = round($refund);
                $item->commission_fee = round($fee);
                $actualRevenue = max(0, $amount - $refund);
                $item->payout_amount = round($actualRevenue - $fee);
                $item->payment_vat = round($vat);
                $item->hotel_net_room = max(0, $item->payout_amount - $vat);

                return $item;
            });

            return response()->json([
                'message' => 'Thành công',
                'stats' => [
                    'grand_total' => $grandTotal,
                    'vnpay_total' => $vnpayTotal,
                    'cash_total' => $cashTotal,
                    'transfer_total' => $transferTotal,
                    'pos_total' => $posTotal,
                    'counter_total' => $counterTotal,
                    'total_commission' => $totalCommission,
                    'total_payout' => $totalPayout,
                    'total_vat' => $totalVat,
                    'total_net_room' => $totalNetRoom,
                    'commission_rate' => $defaultCommission,
                    'hotel_name' => $hotel->name ?? '',
                    'success_count' => $totalSuccessCount,
                    'failed_count' => $totalFailedCount,
                    'pending_count' => $totalPendingCount,
                    'peak_date' => $peakDate,
                    'peak_revenue' => $peakRevenue,
                    'lowest_date' => $lowestDate,
                    'lowest_revenue' => $lowestRevenue
                ],
                'data' => $transactions
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    public function export(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Lỗi xác thực'], 400);

        $hotel = DB::table('hotels')->where('id', $hotelId)->first();
        $defaultCommission = (float)($hotel->commission_rate ?? 15);

        $startDate = \Carbon\Carbon::parse($request->query('start_date', '2026-01-01'))->startOfDay();
        $endDate = \Carbon\Carbon::parse($request->query('end_date', \Carbon\Carbon::today()->toDateString()))->endOfDay();

        $query = \Illuminate\Support\Facades\DB::table('payments')
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->leftJoin('hotels', 'bookings.hotel_id', '=', 'hotels.id')
            ->select(
                'bookings.booking_code',
                'bookings.guest_name',
                'bookings.guest_phone',
                'payments.amount',
                'payments.payment_method',
                'payments.payment_status',
                'payments.created_at',
                'bookings.commission_rate',
                'bookings.platform_fee as booking_platform_fee',
                'bookings.status as booking_status',
                'bookings.refund_amount',
                'bookings.vat_amount',
                'bookings.vat_rate',
                DB::raw('COALESCE(bookings.commission_rate, hotels.commission_rate, ' . $defaultCommission . ') as applied_rate'),
                DB::raw('(CASE WHEN payments.amount = bookings.total_amount AND bookings.platform_fee > 0 THEN ROUND(bookings.platform_fee) ELSE ROUND((CASE WHEN payments.amount > COALESCE(bookings.refund_amount, 0) AND bookings.status = 4 AND payments.payment_method = 4 THEN payments.amount - bookings.refund_amount ELSE payments.amount END) * (COALESCE(bookings.commission_rate, hotels.commission_rate, ' . $defaultCommission . ') / 100)) END) as commission_fee'),
                DB::raw('(CASE WHEN payments.amount = bookings.total_amount AND bookings.vat_amount > 0 THEN ROUND(bookings.vat_amount) ELSE ROUND((CASE WHEN payments.amount > COALESCE(bookings.refund_amount, 0) AND bookings.status = 4 AND payments.payment_method = 4 THEN payments.amount - bookings.refund_amount ELSE payments.amount END) * (COALESCE(bookings.vat_rate, 10) / (100 + COALESCE(bookings.vat_rate, 10)))) END) as payment_vat'),
                DB::raw('(CASE WHEN bookings.status = 4 AND payments.payment_method = 4 THEN COALESCE(bookings.refund_amount, 0) ELSE 0 END) as refund_deducted')
            )
            ->where('bookings.hotel_id', $hotelId)
            ->whereBetween('payments.created_at', [$startDate, $endDate]);

        if ($request->has('keyword') && !empty($request->query('keyword'))) {
            $kw = trim($request->query('keyword'));
            $query->where(function($q) use ($kw) {
                $q->where('bookings.booking_code', 'like', "%{$kw}%")
                  ->orWhere('bookings.guest_name', 'like', "%{$kw}%")
                  ->orWhere('bookings.guest_phone', 'like', "%{$kw}%");
            });
        }
        if ($request->has('method') && $request->query('method') !== 'all') {
            $query->where('payments.payment_method', $request->query('method'));
        }
        if ($request->has('status') && $request->query('status') !== 'all') {
            $query->where('payments.payment_status', $request->query('status'));
        }

        $transactions = $query->orderBy('payments.created_at', 'desc')->get();

        $fileName = 'Doi_Soat_Doanh_Thu_' . date('Ymd_His') . '.csv';

        $headers = [
            "Content-type"        => "text/csv; charset=UTF-8",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $callback = function () use ($transactions) {
            $file = fopen('php://output', 'w');
            fputs($file, "\xEF\xBB\xBF");
            fputcsv($file, [
                'Mã đơn',
                'Thời gian',
                'Khách hàng',
                'Số điện thoại',
                'Hình thức',
                'Khách thanh toán (VNĐ)',
                'Hoàn tiền (VNĐ)',
                'Thuế VAT (KS nộp)',
                'Tỷ lệ hoa hồng (%)',
                'Phí Sàn thu (Admin)',
                'Thực nhận của KS (Gồm VAT)',
                'Trạng thái'
            ], ';');

            foreach ($transactions as $t) {
                $amount = (float)$t->amount;
                $refund = (float)$t->refund_deducted;
                $actualRevenue = max(0, $amount - $refund);
                $rate = (float)$t->applied_rate;
                $commissionFee = (float)$t->commission_fee;
                $payout = round($actualRevenue - $commissionFee);
                $paymentVat = (float)$t->payment_vat;

                fputcsv($file, [
                    $t->booking_code,
                    $t->created_at,
                    $t->guest_name,
                    $t->guest_phone ?? '',
                    $this->getMethodNameForExport($t->payment_method),
                    round($amount),
                    round($refund),
                    round($paymentVat),
                    $rate . '%',
                    round($commissionFee),
                    round($payout),
                    $t->payment_status == 1 ? 'Thành công' : ($t->payment_status == 0 ? 'Đang chờ' : 'Đã hoàn')
                ], ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function getMethodNameForExport(int $method) {
        switch ($method) {
            case 1: return 'Tiền mặt';
            case 2: return 'Quẹt thẻ POS';
            case 3: return 'Chuyển khoản (QR)';
            case 4: return 'VNPAY';
            default: return 'Khác';
        }
    }
}
