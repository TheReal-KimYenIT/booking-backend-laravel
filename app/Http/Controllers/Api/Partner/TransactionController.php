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
                    'bookings.commission_rate as booking_commission_rate', // % Hoa hồng đã chốt
                    'hotels.name as hotel_name',
                    'hotels.id as hotel_id',
                    'hotels.commission_rate as hotel_commission_rate'
                )
                ->where('bookings.hotel_id', $hotelId) // 👉 BẢO MẬT: Chỉ lấy đơn của KS này
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

            // Tính thống kê tổng quan
            $statsQuery = clone $query;
            $allFilteredData = $statsQuery->get();

            $vnpayTotal = $allFilteredData->where('payment_method', 4)->where('payment_status', 1)->sum('amount');
            $cashTotal = $allFilteredData->where('payment_method', 1)->where('payment_status', 1)->sum('amount');
            $posTotal = $allFilteredData->where('payment_method', 2)->where('payment_status', 1)->sum('amount');
            $totalSuccessCount = $allFilteredData->where('payment_status', 1)->count();
            $totalFailedCount = $allFilteredData->where('payment_status', 2)->count();

            // Tính toán % hoa hồng và tiền thực nhận cho từng giao dịch
            $transactions = $query->orderBy('payments.created_at', 'desc')->paginate(15);
            $transactions->getCollection()->transform(function ($item) {
                $rate = $item->booking_commission_rate ?? ($item->hotel_commission_rate ?? 15);

                $item->applied_rate = $rate;
                $item->commission_fee = $item->amount * ($rate / 100);
                $item->payout_amount = $item->amount - $item->commission_fee;
                return $item;
            });

            return response()->json([
                'message' => 'Thành công',
                'stats' => [
                    'vnpay_total' => $vnpayTotal,
                    'cash_total' => $cashTotal,
                    'pos_total' => $posTotal,
                    'success_count' => $totalSuccessCount,
                    'failed_count' => $totalFailedCount,
                ],
                'data' => $transactions
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
}
