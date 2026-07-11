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
            $startDate = Carbon::parse($request->query('start_date', Carbon::today()->startOfMonth()->toDateString()))->startOfDay();
            $endDate = Carbon::parse($request->query('end_date', Carbon::today()->toDateString()))->endOfDay();

            $query = DB::table('payments')
                ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
                ->leftJoin('hotels', 'bookings.hotel_id', '=', 'hotels.id')
                ->select(
                    'payments.*',
                    'bookings.booking_code',
                    'bookings.guest_name',
                    'bookings.guest_phone',
                    'bookings.commission_rate as booking_commission_rate', // 👉 Ưu tiên % đã chốt trong đơn
                    'hotels.name as hotel_name',
                    'hotels.id as hotel_id',
                    'hotels.commission_rate as hotel_commission_rate' // 👉 Dự phòng cho các đơn quá cũ
                )
                ->whereBetween('payments.created_at', [$startDate, $endDate]);

            // Lọc theo keyword (Mã đơn)
            if ($request->has('keyword') && !empty($request->query('keyword'))) {
                $query->where('bookings.booking_code', 'like', '%' . $request->query('keyword') . '%');
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

            // --- XỬ LÝ SỐ LIỆU TỔNG & BIỂU ĐỒ ---
            $statsQuery = clone $query;
            $allFilteredData = $statsQuery->get();

            $vnpayTotal = $allFilteredData->where('payment_method', 4)->where('payment_status', 1)->sum('amount');
            $cashTotal = $allFilteredData->where('payment_method', 1)->where('payment_status', 1)->sum('amount');
            $posTotal = $allFilteredData->where('payment_method', 2)->where('payment_status', 1)->sum('amount');
            $totalSuccessCount = $allFilteredData->where('payment_status', 1)->count();
            $totalFailedCount = $allFilteredData->where('payment_status', 2)->count();

            // 👉 Tạo dữ liệu cho Biểu đồ (Doanh thu VNPAY theo ngày)
            $vnpayTransactions = $allFilteredData->where('payment_method', 4)->where('payment_status', 1);
            $chartDataRaw = $vnpayTransactions->groupBy(function ($item) {
                return Carbon::parse($item->created_at)->format('d/m/Y');
            })->map(function ($row) {
                return $row->sum('amount');
            });

            // Phân trang
            $transactions = $query->orderBy('payments.created_at', 'desc')->paginate(15);

            $transactions->getCollection()->transform(function ($item) {
                // Nếu đơn hàng có lưu % (đơn mới) -> Dùng % đó. Nếu không (đơn cũ) -> Dùng của KS
                $rate = $item->booking_commission_rate ?? ($item->hotel_commission_rate ?? 15);

                $item->applied_rate = $rate; // Truyền xuống Angular để hiển thị
                $item->commission_fee = $item->amount * ($rate / 100);
                $item->payout_amount = $item->amount - $item->commission_fee;
                return $item;
            });
            $hotels = DB::table('hotels')->select('id', 'name')->where('status', 1)->get();

            return response()->json([
                'message' => 'Lấy danh sách giao dịch thành công',
                'stats' => [
                    'vnpay_total' => $vnpayTotal,
                    'cash_total' => $cashTotal,
                    'pos_total' => $posTotal,
                    'success_count' => $totalSuccessCount,
                    'failed_count' => $totalFailedCount,
                ],
                'chart' => [
                    'labels' => $chartDataRaw->keys()->toArray(),
                    'data' => $chartDataRaw->values()->toArray(),
                ],
                'hotels' => $hotels,
                'data' => $transactions
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage(), 'line' => $e->getLine()], 500);
        }
    }

    // XUẤT FILE EXCEL / CSV (Thêm cột Đối soát)
    public function exportCsv(Request $request)
    {
        $startDate = Carbon::parse($request->query('start_date', Carbon::today()->startOfMonth()->toDateString()))->startOfDay();
        $endDate = Carbon::parse($request->query('end_date', Carbon::today()->toDateString()))->endOfDay();

        $query = DB::table('payments')
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->leftJoin('hotels', 'bookings.hotel_id', '=', 'hotels.id')
            ->select('payments.*', 'bookings.booking_code', 'bookings.guest_name', 'hotels.name as hotel_name', 'hotels.commission_rate')
            ->whereBetween('payments.created_at', [$startDate, $endDate]);

        if ($request->has('keyword') && !empty($request->query('keyword'))) {
            $query->where('bookings.booking_code', 'like', '%' . $request->query('keyword') . '%');
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

        $filename = "Doi_Soat_StayBook_" . date('Ymd_His') . ".csv";
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

            // 👉 Thêm cột Tỉ lệ %, Phí sàn, Thực trả
            fputcsv($file, ['Thời Gian', 'Mã Đơn', 'Khách Hàng', 'Khách Sạn', 'Hình Thức', 'Mã GD VNPAY', 'Tổng Quẹt (VNĐ)', 'Tỉ lệ HH', 'Phí Sàn Thu (VNĐ)', 'Thực Trả KS (VNĐ)', 'Trạng Thái'], ';');

            foreach ($transactions as $row) {
                $methodName = $row->payment_method == 4 ? 'VNPAY' : ($row->payment_method == 1 ? 'Tiền Mặt' : 'Quẹt POS');
                $statusName = $row->payment_status == 1 ? 'Thành Công' : ($row->payment_status == 0 ? 'Đang Chờ' : 'Thất Bại');

                $rate = $row->booking_commission_rate ?? ($row->hotel_commission_rate ?? 15);
                $fee = $row->amount * ($rate / 100);
                $payout = $row->amount - $fee;

                fputcsv($file, [
                    $row->created_at,
                    $row->booking_code,
                    $row->guest_name,
                    $row->hotel_name,
                    $methodName,
                    $row->transaction_id ?? '---',
                    $row->amount,
                    $rate . '%',
                    $fee,
                    $payout,
                    $statusName
                ], ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
