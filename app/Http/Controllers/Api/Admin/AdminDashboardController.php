<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Customer;
use Carbon\Carbon;

class AdminDashboardController extends Controller
{
    public function getStats(Request $request)
    {
        // 1. Total Partners (Approved Hotels vs Pending)
        $totalPartners = Hotel::where("status", 1)->count();
        $pendingPartners = Hotel::where("status", 0)->count();

        // 2. Total Customers
        $totalCustomers = Customer::count();

        // 3. System Booking Statuses
        $systemBookingStatus = \Illuminate\Support\Facades\DB::table('bookings')
            ->selectRaw('
                COUNT(*) as total_all,
                SUM(CASE WHEN status IN (1,2,3) THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN status = 4 THEN 1 ELSE 0 END) as cancelled_count,
                SUM(CASE WHEN status = 5 THEN 1 ELSE 0 END) as noshow_count
            ')->first();

        $totalAllBookings = (int) ($systemBookingStatus->total_all ?? 0);
        $successBookings = (int) ($systemBookingStatus->success_count ?? 0);
        $cancelledBookings = (int) ($systemBookingStatus->cancelled_count ?? 0);
        $noshowBookings = (int) ($systemBookingStatus->noshow_count ?? 0);

        $successRate = $totalAllBookings > 0 ? round(($successBookings / $totalAllBookings) * 100, 1) : 0;
        $cancellationRate = $totalAllBookings > 0 ? round(($cancelledBookings / $totalAllBookings) * 100, 1) : 0;

        // 4. Total Platform Revenue (Commission) & Total GMV
        $totalPlatformRevenue = (float) Booking::where(function ($query) {
            $query->whereIn("status", [1, 2, 3])
                  ->orWhere(function ($q) {
                      $q->where("status", 4)->where("platform_fee", ">", 0);
                  });
        })->sum('platform_fee');

        $totalGmv = (float) Booking::whereIn("status", [1, 2, 3])->sum('total_amount');
        $avgBookingValue = $successBookings > 0 ? round($totalGmv / $successBookings) : 0;

        // Tỉ lệ tăng trưởng doanh thu hoa hồng (MoM Growth Rate)
        $now = Carbon::now();
        $currentMonthRevenue = (float) Booking::whereIn("status", [1, 2, 3])
            ->whereYear("created_at", $now->year)
            ->whereMonth("created_at", $now->month)
            ->sum('platform_fee');

        $lastMonthRevenue = (float) Booking::whereIn("status", [1, 2, 3])
            ->whereYear("created_at", $now->copy()->subMonth()->year)
            ->whereMonth("created_at", $now->copy()->subMonth()->month)
            ->sum('platform_fee');

        $growthRate = null;
        if ($currentMonthRevenue > 0 && $lastMonthRevenue > 0) {
            $growthRate = round((($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1);
        } elseif ($currentMonthRevenue > 0 && $lastMonthRevenue == 0) {
            $growthRate = 100.0;
        } else {
            // Nếu tháng hiện tại chưa có đơn (đầu tháng), so sánh tháng gần nhất có giao dịch với tháng trước đó
            $latestBooking = Booking::whereIn("status", [1, 2, 3])->latest('created_at')->first();
            if ($latestBooking) {
                $refDate = Carbon::parse($latestBooking->created_at);
                $refRevenue = (float) Booking::whereIn("status", [1, 2, 3])
                    ->whereYear("created_at", $refDate->year)
                    ->whereMonth("created_at", $refDate->month)
                    ->sum('platform_fee');

                $prevRevenue = (float) Booking::whereIn("status", [1, 2, 3])
                    ->whereYear("created_at", $refDate->copy()->subMonth()->year)
                    ->whereMonth("created_at", $refDate->copy()->subMonth()->month)
                    ->sum('platform_fee');

                if ($prevRevenue > 0) {
                    $growthRate = round((($refRevenue - $prevRevenue) / $prevRevenue) * 100, 1);
                }
            }
        }

        // 5. Revenue Chart (Dual: GMV & Hoa hồng)
        $period = $request->query('period', 'month'); // day, month, year
        $revenueChart = [];
        
        if ($period === 'day') {
            $startDate = $now->copy()->subDays(9)->startOfDay();
            $dbSums = Booking::whereIn('status', [1, 2, 3])
                ->where('created_at', '>=', $startDate)
                ->selectRaw('DATE(created_at) as pay_date, SUM(platform_fee) as fee_total, SUM(total_amount) as gmv_total')
                ->groupBy(\Illuminate\Support\Facades\DB::raw('DATE(created_at)'))
                ->get()
                ->keyBy('pay_date');

            for ($i = 9; $i >= 0; $i--) {
                $date = $now->copy()->subDays($i);
                $key = $date->toDateString();
                $label = $date->format("d/m");
                $fee = isset($dbSums[$key]) ? (float)$dbSums[$key]->fee_total : 0.0;
                $gmv = isset($dbSums[$key]) ? (float)$dbSums[$key]->gmv_total : 0.0;
                $revenueChart[] = ["label" => $label, "revenue" => round($fee), "gmv" => round($gmv)];
            }
        } elseif ($period === 'year') {
            $startYear = $now->copy()->subYears(4)->year;
            $dbSums = Booking::whereIn('status', [1, 2, 3])
                ->whereYear('created_at', '>=', $startYear)
                ->selectRaw('YEAR(created_at) as pay_year, SUM(platform_fee) as fee_total, SUM(total_amount) as gmv_total')
                ->groupBy(\Illuminate\Support\Facades\DB::raw('YEAR(created_at)'))
                ->get()
                ->keyBy('pay_year');

            for ($i = 4; $i >= 0; $i--) {
                $date = $now->copy()->subYears($i);
                $key = $date->year;
                $label = (string)$date->year;
                $fee = isset($dbSums[$key]) ? (float)$dbSums[$key]->fee_total : 0.0;
                $gmv = isset($dbSums[$key]) ? (float)$dbSums[$key]->gmv_total : 0.0;
                $revenueChart[] = ["label" => $label, "revenue" => round($fee), "gmv" => round($gmv)];
            }
        } else { // month
            $startDate = $now->copy()->subMonths(5)->startOfMonth();
            $dbSums = Booking::whereIn('status', [1, 2, 3])
                ->where('created_at', '>=', $startDate)
                ->selectRaw("DATE_FORMAT(created_at, '%m/%Y') as pay_month, SUM(platform_fee) as fee_total, SUM(total_amount) as gmv_total")
                ->groupBy(\Illuminate\Support\Facades\DB::raw("DATE_FORMAT(created_at, '%m/%Y')"))
                ->get()
                ->keyBy('pay_month');

            for ($i = 5; $i >= 0; $i--) {
                $date = $now->copy()->subMonths($i);
                $label = $date->format("m/Y");
                $fee = isset($dbSums[$label]) ? (float)$dbSums[$label]->fee_total : 0.0;
                $gmv = isset($dbSums[$label]) ? (float)$dbSums[$label]->gmv_total : 0.0;
                $revenueChart[] = ["label" => $label, "revenue" => round($fee), "gmv" => round($gmv)];
            }
        }

        // 6. Partner Status Chart (Approved vs Pending)
        $partnerChart = [
            ["status" => "Đã duyệt", "count" => $totalPartners],
            ["status" => "Chờ duyệt", "count" => $pendingPartners]
        ];

        // 7. System Booking Status Chart (Pie Chart)
        $systemStatusChart = [
            ["status" => "Thành công", "count" => $successBookings, "percent" => $successRate],
            ["status" => "Đã hủy", "count" => $cancelledBookings, "percent" => $cancellationRate],
            ["status" => "No-Show", "count" => $noshowBookings, "percent" => $totalAllBookings > 0 ? round(($noshowBookings / $totalAllBookings) * 100, 1) : 0]
        ];

        // 8. Payment Methods Breakdown (Cơ cấu thanh toán: VNPAY, Chuyển khoản, Tiền mặt)
        $methodRows = \Illuminate\Support\Facades\DB::table('payments')
            ->where('payment_status', 1)
            ->select('payment_method', \Illuminate\Support\Facades\DB::raw('COUNT(*) as count'), \Illuminate\Support\Facades\DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->get();

        $paymentMethodsMap = [
            4 => ['name' => 'Cổng VNPAY (Online)', 'color' => '#3B82F6', 'icon' => 'bi-globe'],
            3 => ['name' => 'Chuyển khoản (Bank QR)', 'color' => '#8B5CF6', 'icon' => 'bi-bank'],
            1 => ['name' => 'Tiền mặt tại quầy', 'color' => '#10B981', 'icon' => 'bi-cash-stack']
        ];

        $paymentMethodsChart = [];
        $totalPaidSum = $methodRows->sum('total');
        foreach ([4, 3, 1] as $mId) {
            $found = $methodRows->firstWhere('payment_method', $mId);
            $totalVal = $found ? round((float)$found->total) : 0;
            $countVal = $found ? (int)$found->count : 0;
            $pct = $totalPaidSum > 0 ? round(($totalVal / $totalPaidSum) * 100, 1) : 0;
            $paymentMethodsChart[] = [
                'id' => $mId,
                'name' => $paymentMethodsMap[$mId]['name'],
                'color' => $paymentMethodsMap[$mId]['color'],
                'icon' => $paymentMethodsMap[$mId]['icon'],
                'total' => $totalVal,
                'count' => $countVal,
                'percent' => $pct
            ];
        }

        // 9. City Distribution (Thị trường trọng điểm)
        $cityStats = \Illuminate\Support\Facades\DB::table('hotels')
            ->where('status', 1)
            ->select('city', \Illuminate\Support\Facades\DB::raw('COUNT(*) as count'))
            ->groupBy('city')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        // 10. Top Hotels Ranking with Podium #1, #2, #3
        $hotelStats = \Illuminate\Support\Facades\DB::table('bookings')
            ->join('hotels', 'bookings.hotel_id', '=', 'hotels.id')
            ->select(
                'hotels.id',
                'hotels.name',
                'hotels.city',
                'hotels.star_rating',
                \Illuminate\Support\Facades\DB::raw('ROUND(SUM(bookings.platform_fee)) as total_commission'),
                \Illuminate\Support\Facades\DB::raw('ROUND(SUM(bookings.total_amount)) as total_gmv'),
                \Illuminate\Support\Facades\DB::raw('COUNT(bookings.id) as booking_count')
            )
            ->whereIn('bookings.status', [1, 2, 3])
            ->groupBy('hotels.id', 'hotels.name', 'hotels.city', 'hotels.star_rating')
            ->orderByDesc('total_commission')
            ->get();

        $revenueByHotel = $hotelStats->take(5);
        $top3Hotels = $hotelStats->take(3);
        $topHotel = $hotelStats->first();
        $bottomHotel = ($hotelStats->count() > 1) ? $hotelStats->last() : null;

        // 11. Recent 5 Bookings Activity
        $recentBookings = \Illuminate\Support\Facades\DB::table('bookings')
            ->join('hotels', 'bookings.hotel_id', '=', 'hotels.id')
            ->select(
                'bookings.id',
                'bookings.booking_code',
                'bookings.guest_name',
                'bookings.guest_phone',
                'bookings.total_amount',
                'bookings.platform_fee',
                'bookings.status',
                'bookings.created_at',
                'hotels.name as hotel_name'
            )
            ->orderByDesc('bookings.created_at')
            ->limit(5)
            ->get();

        return response()->json([
            "total_partners" => $totalPartners,
            "pending_partners" => $pendingPartners,
            "total_customers" => $totalCustomers,
            "total_bookings" => $successBookings,
            "total_all_bookings" => $totalAllBookings,
            "success_rate" => $successRate,
            "cancellation_rate" => $cancellationRate,
            "total_platform_revenue" => round($totalPlatformRevenue),
            "total_gmv" => round($totalGmv),
            "avg_booking_value" => $avgBookingValue,
            "growth_rate" => $growthRate,
            "revenue_chart" => $revenueChart,
            "partner_chart" => $partnerChart,
            "system_status_chart" => $systemStatusChart,
            "payment_methods_chart" => $paymentMethodsChart,
            "city_stats" => $cityStats,
            "revenue_by_hotel" => $revenueByHotel,
            "top_3_hotels" => $top3Hotels,
            "top_hotel" => $topHotel,
            "bottom_hotel" => $bottomHotel,
            "recent_bookings" => $recentBookings
        ], 200);
    }
}
