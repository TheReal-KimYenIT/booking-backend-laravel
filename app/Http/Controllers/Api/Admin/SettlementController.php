<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Dompdf\Dompdf;
use Dompdf\Options;

class SettlementController extends Controller
{
    public function index(Request $request)
    {
        $month = $request->query('month', date('m'));
        $year = $request->query('year', date('Y'));

        $data = $this->calculateSettlement($month, $year);

        return response()->json([
            'message' => 'Lấy dữ liệu công nợ thành công',
            'month' => "$month/$year",
            'data' => array_values($data)
        ], 200);
    }

    public function exportPdf(Request $request)
    {
        try {
            $month = $request->query('month', date('m'));
            $year = $request->query('year', date('Y'));
            $hotelId = $request->query('hotel_id');

            if (!$hotelId) {
                return response()->json(['message' => 'Thiếu ID khách sạn'], 400);
            }

            $allData = $this->calculateSettlement($month, $year);
            $hotelData = collect($allData)->firstWhere('hotel_id', (int)$hotelId);

            if (!$hotelData) {
                return response()->json(['message' => 'Không phát sinh giao dịch trong tháng này'], 404);
            }

            if (!view()->exists('pdf.settlement')) {
                throw new \Exception('Không tìm thấy file resources/views/pdf/settlement.blade.php');
            }

            $dataForPdf = [
                'month' => $month,
                'year' => $year,
                'hotel' => $hotelData,
                'export_date' => date('d/m/Y H:i:s'),
            ];

            $html = view('pdf.settlement', $dataForPdf)->render();

            $options = new Options();
            $options->set('defaultFont', 'sans-serif');
            $options->set('isRemoteEnabled', true);
            $options->set('chroot', public_path());

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $fileName = "Chot_Cong_No_{$hotelData['hotel_name']}_{$month}_{$year}.pdf";

            return response($dompdf->output(), 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Lỗi xuất PDF: ' . $e->getMessage());
            return response()->json(['message' => 'Lỗi tạo PDF: ' . $e->getMessage()], 500);
        }
    }

    private function calculateSettlement(int|string $month, int|string $year)
    {
        $payments = DB::table('payments')
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->join('hotels', 'bookings.hotel_id', '=', 'hotels.id')
            ->select(
                'payments.*',
                'bookings.commission_rate as booking_commission_rate',
                'hotels.name as hotel_name',
                'hotels.id as hotel_id'
            )
            ->whereMonth('payments.created_at', $month)
            ->whereYear('payments.created_at', $year)
            ->where('payments.payment_status', 1)
            ->get();

        $savedSettlements = DB::table('settlements')
            ->where('month', $month)
            ->where('year', $year)
            ->get()
            ->keyBy('hotel_id');

        $grouped = $payments->groupBy('hotel_id')->map(function ($hotelPayments, $hotelId) use ($savedSettlements) {
            $hotelName = $hotelPayments->first()->hotel_name;

            $vnpayTotal = $hotelPayments->where('payment_method', 4)->sum('amount');
            $cashTotal = $hotelPayments->whereIn('payment_method', [1, 2, 3])->sum('amount');
            $totalRevenue = $vnpayTotal + $cashTotal;

            $commissionTotal = $hotelPayments->sum(function ($p) {
                $rate = $p->booking_commission_rate ?? 15;
                return $p->amount * ($rate / 100);
            });

            $payoutToHotel = $vnpayTotal - $commissionTotal;
            $saved = $savedSettlements->get($hotelId);

            return [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'total_revenue' => $totalRevenue,
                'vnpay_total' => $vnpayTotal,
                'cash_total' => $cashTotal,
                'commission_total' => $commissionTotal,
                'payout_to_hotel' => $payoutToHotel,
                'is_debt' => $payoutToHotel < 0,
                'status' => $saved ? $saved->status : 0,
                'proof_image' => $saved ? $saved->proof_image : null,
                'paid_at' => $saved ? $saved->paid_at : null,
            ];
        });

        return $grouped->toArray();
    }

    public function confirmPayment(Request $request)
    {
        $request->validate([
            'hotel_id' => 'required|integer',
            'month' => 'required|integer',
            'year' => 'required|integer',
            'proof_image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        $hId = $request->hotel_id;
        $m = $request->month;
        $y = $request->year;

        $hotelPayments = DB::table('payments')
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->where('bookings.hotel_id', $hId)
            ->whereMonth('payments.created_at', $m)
            ->whereYear('payments.created_at', $y)
            ->where('payments.payment_status', 1)
            ->get();

        $vnpay = $hotelPayments->where('payment_method', 4)->sum('amount');
        $cash = $hotelPayments->whereIn('payment_method', [1, 2, 3])->sum('amount');

        $comm = $hotelPayments->sum(function ($p) {
            $rate = DB::table('bookings')->where('id', $p->booking_id)->value('commission_rate') ?? 15;
            return $p->amount * ($rate / 100);
        });

        $payout = $vnpay - $comm;

        $proofImagePath = null;
        if ($request->hasFile('proof_image')) {
            $path = $request->file('proof_image')->store('settlements', 'public');
            $proofImagePath = '/storage/' . $path;
        }

        $settlement = DB::table('settlements')
            ->where('hotel_id', $hId)
            ->where('month', $m)
            ->where('year', $y)
            ->first();

        // Xử lý status dựa trên payout
        $status = $payout >= 0 ? 2 : 1;

        $data = [
            'total_revenue'    => $vnpay + $cash,
            'vnpay_total'      => $vnpay,
            'commission_total' => $comm,
            'payout_to_hotel'  => $payout,
            'status'           => $status,
            'paid_at'          => now(),
            'updated_at'       => now()
        ];

        if ($proofImagePath) {
            $data['proof_image'] = $proofImagePath;
        }

        if ($settlement) {
            DB::table('settlements')->where('id', $settlement->id)->update($data);
        } else {
            $data['hotel_id'] = $hId;
            $data['month'] = $m;
            $data['year'] = $y;
            $data['created_at'] = now();
            DB::table('settlements')->insert($data);
        }

        return response()->json(['message' => 'Xác nhận thành công!'], 200);
    }
}
