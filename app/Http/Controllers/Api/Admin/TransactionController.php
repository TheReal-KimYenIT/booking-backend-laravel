<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        try {
            $startDate = Carbon::parse($request->query('start_date', '2026-01-01'))->startOfDay();
            $endDate = Carbon::parse($request->query('end_date', Carbon::today()->toDateString()))->endOfDay();

            $query = DB::table('payments')
                ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
                ->leftJoin('hotels', 'bookings.hotel_id', '=', 'hotels.id')
                ->whereBetween('payments.created_at', [$startDate, $endDate]);

            // Các bộ lọc
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
            if ($request->has('hotel_id') && $request->query('hotel_id') !== 'all') {
                $query->where('bookings.hotel_id', $request->query('hotel_id'));
            }

            // --- TÍNH TOÁN THỐNG KÊ TỔNG QUAN BẰNG SQL TRỰC TIẾP TRONG DATABASE (CỰC NHANH & CHÍNH XÁC) ---
            $sqlStats = (clone $query)->selectRaw('
                COALESCE(SUM(CASE WHEN payments.payment_status = 1 THEN payments.amount ELSE 0 END), 0) as gross_total,
                COALESCE(SUM(CASE WHEN payments.payment_status = 1 AND payments.payment_method = 4 THEN payments.amount ELSE 0 END), 0) as vnpay_total,
                COALESCE(SUM(CASE WHEN payments.payment_status = 1 AND payments.payment_method = 1 THEN payments.amount ELSE 0 END), 0) as cash_total,
                COALESCE(SUM(CASE WHEN payments.payment_status = 1 AND payments.payment_method = 3 THEN payments.amount ELSE 0 END), 0) as transfer_total,
                COALESCE(SUM(CASE WHEN payments.payment_status = 1 AND payments.payment_method = 2 THEN payments.amount ELSE 0 END), 0) as pos_total,
                COALESCE(SUM(CASE 
                    WHEN payments.payment_status = 1 THEN 
                        CASE 
                            WHEN payments.amount = bookings.total_amount AND bookings.platform_fee > 0 THEN bookings.platform_fee
                            ELSE payments.amount * (COALESCE(bookings.commission_rate, hotels.commission_rate, 15) / 100)
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

            $grossTotal = round((float)$sqlStats->gross_total);
            $vnpayTotal = round((float)$sqlStats->vnpay_total);
            $cashTotal = round((float)$sqlStats->cash_total);
            $transferTotal = round((float)$sqlStats->transfer_total);
            $posTotal = round((float)$sqlStats->pos_total);
            $totalCommission = round((float)$sqlStats->total_commission);
            $totalPayout = $grossTotal - $totalCommission;
            $totalVat = round((float)$sqlStats->total_vat);

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

            // Phân trang với select chi tiết (sử dụng trực tiếp trường có sẵn và tính toán SQL)
            $perPage = min(5000, max(1, (int)$request->query('limit', $request->query('per_page', 15))));
            $transactions = (clone $query)->select(
                'payments.*',
                'bookings.booking_code',
                'bookings.guest_name',
                'bookings.guest_phone',
                'bookings.guest_email',
                'bookings.status as booking_status',
                'bookings.refund_amount',
                'bookings.total_amount',
                'bookings.vat_amount',
                'bookings.vat_rate',
                'bookings.check_in',
                'bookings.check_out',
                'bookings.commission_rate as booking_commission_rate',
                'bookings.platform_fee as booking_platform_fee',
                'hotels.name as hotel_name',
                'hotels.id as hotel_id',
                'hotels.commission_rate as hotel_commission_rate',
                DB::raw('COALESCE(bookings.commission_rate, hotels.commission_rate, 15) as applied_rate'),
                DB::raw('(CASE WHEN payments.amount = bookings.total_amount AND bookings.platform_fee > 0 THEN ROUND(bookings.platform_fee) ELSE ROUND(payments.amount * (COALESCE(bookings.commission_rate, hotels.commission_rate, 15) / 100)) END) as commission_fee'),
                DB::raw('(CASE WHEN payments.amount = bookings.total_amount AND bookings.vat_amount > 0 THEN ROUND(bookings.vat_amount) ELSE ROUND(payments.amount * (COALESCE(bookings.vat_rate, 10) / (100 + COALESCE(bookings.vat_rate, 10)))) END) as payment_vat'),
                DB::raw('(CASE WHEN payments.payment_status = 2 THEN payments.amount WHEN bookings.status = 4 THEN COALESCE(bookings.refund_amount, 0) ELSE 0 END) as refund_deducted')
            )->orderBy('payments.created_at', 'desc')->paginate($perPage);

            $transactions->getCollection()->transform(function ($item) {
                $amount = (float)$item->amount;
                $fee = (float)$item->commission_fee;
                $vat = (float)$item->payment_vat;

                $item->applied_rate = (float)$item->applied_rate;
                $item->vat_rate = (float)($item->vat_rate ?? 10);
                $item->commission_fee = $fee;
                $item->payment_vat = $vat;
                $item->refund_deducted = (float)$item->refund_deducted;
                $item->payout_amount = $amount - $fee;
                $item->hotel_net_room = max(0, $item->payout_amount - $vat);
                $item->booking_total_vat = (float)($item->vat_amount ?? 0);
                return $item;
            });

            $hotels = DB::table('hotels')->select('id', 'name')->where('status', 1)->orderBy('name')->get();

            return response()->json([
                'message' => 'Lấy danh sách giao dịch thành công',
                'stats' => [
                    'gross_total' => $grossTotal,
                    'vnpay_total' => $vnpayTotal,
                    'cash_total' => $cashTotal,
                    'transfer_total' => $transferTotal,
                    'pos_total' => $posTotal,
                    'commission_total' => $totalCommission,
                    'payout_total' => $totalPayout,
                    'total_vat' => $totalVat,
                    'success_count' => $totalSuccessCount,
                    'failed_count' => $totalFailedCount,
                    'pending_count' => $totalPendingCount,
                    'peak_date' => $peakDate,
                    'peak_revenue' => $peakRevenue,
                    'lowest_date' => $lowestDate,
                    'lowest_revenue' => $lowestRevenue
                ],
                'hotels' => $hotels,
                'data' => $transactions
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    public function exportCsv(Request $request)
    {
        return $this->exportExcel($request);
    }

    public function exportExcel(Request $request)
    {
        $startDate = Carbon::parse($request->query('start_date', '2026-01-01'))->startOfDay();
        $endDate = Carbon::parse($request->query('end_date', Carbon::today()->toDateString()))->endOfDay();

        $query = DB::table('payments')
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->leftJoin('hotels', 'bookings.hotel_id', '=', 'hotels.id')
            ->select(
                'payments.*',
                'bookings.booking_code',
                'bookings.guest_name',
                'bookings.guest_phone',
                'bookings.status as booking_status',
                'bookings.refund_amount',
                'bookings.total_amount',
                'bookings.vat_amount',
                'bookings.vat_rate',
                'bookings.commission_rate as booking_commission_rate',
                'bookings.platform_fee as booking_platform_fee',
                'hotels.name as hotel_name',
                'hotels.commission_rate as hotel_commission_rate',
                DB::raw('COALESCE(bookings.commission_rate, hotels.commission_rate, 15) as applied_rate'),
                DB::raw('(CASE WHEN payments.amount = bookings.total_amount AND bookings.platform_fee > 0 THEN ROUND(bookings.platform_fee) ELSE ROUND(payments.amount * (COALESCE(bookings.commission_rate, hotels.commission_rate, 15) / 100)) END) as commission_fee'),
                DB::raw('(CASE WHEN payments.amount = bookings.total_amount AND bookings.vat_amount > 0 THEN ROUND(bookings.vat_amount) ELSE ROUND(payments.amount * (COALESCE(bookings.vat_rate, 10) / (100 + COALESCE(bookings.vat_rate, 10)))) END) as payment_vat'),
                DB::raw('(CASE WHEN payments.payment_status = 2 THEN payments.amount WHEN bookings.status = 4 THEN COALESCE(bookings.refund_amount, 0) ELSE 0 END) as refund_deducted')
            )
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
        if ($request->has('hotel_id') && $request->query('hotel_id') !== 'all') {
            $query->where('bookings.hotel_id', $request->query('hotel_id'));
        }

        $transactions = $query->orderBy('payments.created_at', 'desc')->get();

        $filename = "Doi_Soat_StayHub_" . date('Ymd_His') . ".csv";
        $headers = [
            "Content-type"        => "text/csv; charset=UTF-8",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $callback = function () use ($transactions) {
            $file = fopen('php://output', 'w');
            fputs($file, "\xEF\xBB\xBF");

            fputcsv($file, ['Thời gian', 'Mã đơn', 'Khách hàng', 'Số điện thoại', 'Khách sạn', 'Hình thức', 'Khách thanh toán', 'Hoàn tiền', 'Thuế VAT đợt này (KS nộp)', 'Tỉ lệ hoa hồng', 'Phí Sàn thu (Admin nhận)', 'Thực nhận của KS (Gồm VAT)', 'Trạng thái'], ';');

            foreach ($transactions as $row) {
                $methodName = $row->payment_method == 4 ? 'VNPAY' : ($row->payment_method == 1 ? 'Tiền mặt' : ($row->payment_method == 2 ? 'Quẹt thẻ POS' : 'Chuyển khoản'));
                $statusName = $row->payment_status == 1 ? 'Thành công' : ($row->payment_status == 0 ? 'Đang chờ' : 'Đã hoàn');

                $amount = (float)$row->amount;
                $fee = (float)$row->commission_fee;
                $payout = $amount - $fee;
                $paymentVat = (float)$row->payment_vat;
                $refund = (float)$row->refund_deducted;
                $rate = (float)$row->applied_rate;

                fputcsv($file, [
                    $row->created_at,
                    $row->booking_code,
                    $row->guest_name,
                    $row->guest_phone ?? '',
                    $row->hotel_name,
                    $methodName,
                    round($amount),
                    round($refund),
                    round($paymentVat),
                    $rate . '%',
                    round($fee),
                    round($payout),
                    $statusName
                ], ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
