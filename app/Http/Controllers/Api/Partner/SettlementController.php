<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Dompdf\Dompdf;
use Dompdf\Options;

class SettlementController extends Controller
{
    /**
     * API 1: Hiển thị dữ liệu công nợ trên màn hình
     */
    public function index(Request $request)
    {
        $partner = auth('partner')->user();

        // BƯỚC SỬA LỖI: Tìm khách sạn trong bảng 'hotels' dựa vào 'partner_id'
        $hotel = DB::table('hotels')->where('partner_id', $partner->id)->first();

        // Kiểm tra xem tìm có ra khách sạn không
        if (!$hotel) {
            return response()->json(['message' => 'Đối tác chưa được gán khách sạn nào trên hệ thống.'], 403);
        }

        $hotelId = $hotel->id; // Lấy ID khách sạn tìm được
        $month = $request->query('month', date('m'));
        $year = $request->query('year', date('Y'));

        $data = $this->calculateData($hotelId, $month, $year);

        return response()->json([
            'data' => $data
        ]);
    }

    /**
     * API 2: Tạo và xuất file PDF
     */
    public function exportPdf(Request $request)
    {
        try {
            $partner = auth('partner')->user();

            // BƯỚC SỬA LỖI: Tìm khách sạn tương tự như hàm index
            $hotel = DB::table('hotels')->where('partner_id', $partner->id)->first();

            if (!$hotel) {
                return response()->json(['message' => 'Đối tác chưa được gán khách sạn.'], 403);
            }

            $hotelId = $hotel->id;
            $month = $request->query('month', date('m'));
            $year = $request->query('year', date('Y'));

            // Lấy dữ liệu công nợ
            $hotelData = $this->calculateData($hotelId, $month, $year);

            // Gắn thêm tên khách sạn để in lên PDF
            $hotelData['hotel_name'] = $hotel->name ?? 'Khách sạn đối tác';

            // Khai báo dữ liệu truyền vào mẫu PDF
            $dataForPdf = [
                'month' => $month,
                'year' => $year,
                'hotel' => $hotelData,
                'export_date' => date('d/m/Y H:i:s'),
            ];

            // Render HTML từ Blade
            $html = view('pdf.settlement', $dataForPdf)->render();

            // Cấu hình Dompdf
            $options = new Options();
            $options->set('defaultFont', 'sans-serif');
            $options->set('isRemoteEnabled', true);
            $options->set('chroot', public_path());

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $fileName = "Chot_Cong_No_Thang_{$month}_{$year}.pdf";

            return response($dompdf->output(), 200)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Lỗi xuất PDF Partner: ' . $e->getMessage());
            return response()->json([
                'message' => 'Lỗi tạo PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Hàm dùng chung để tính toán tiền
     */
    private function calculateData(int|string $hotelId, int|string $month, int|string $year)
    {
        $payments = DB::table('payments')
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->select(
                'payments.*',
                'bookings.status as booking_status',
                'bookings.refund_amount',
                'bookings.commission_rate as booking_commission_rate'
            )
            ->where('bookings.hotel_id', $hotelId)
            ->whereMonth('payments.created_at', $month)
            ->whereYear('payments.created_at', $year)
            ->where('payments.payment_status', 1)
            ->get();

        // 👉 TÍNH TOÁN LẠI TƯƠNG TỰ BÊN ADMIN
        $vnpayTotal = $payments->where('payment_method', 4)->sum(function ($p) {
            $refund = ($p->booking_status == 4) ? ($p->refund_amount ?? 0) : 0;
            return $p->amount - $refund;
        });

        $cashTotal = $payments->whereIn('payment_method', [1, 2, 3])->sum('amount');

        $commissionTotal = $payments->sum(function ($p) {
            $refund = ($p->booking_status == 4 && $p->payment_method == 4) ? ($p->refund_amount ?? 0) : 0;
            $actualRevenue = $p->amount - $refund;
            $rate = $p->booking_commission_rate ?? 15;
            return max(0, $actualRevenue * ($rate / 100));
        });

        $saved = DB::table('settlements')->where('hotel_id', $hotelId)->where('month', $month)->where('year', $year)->first();
        $payoutToHotel = $vnpayTotal - $commissionTotal;

        return [
            'total_revenue' => $vnpayTotal + $cashTotal,
            'vnpay_total' => $vnpayTotal,
            'cash_total' => $cashTotal,
            'commission_total' => $commissionTotal,
            'payout_to_hotel' => $payoutToHotel,
            'is_debt' => $payoutToHotel < 0,
            'status' => $saved ? $saved->status : 0,
            'proof_image' => $saved ? $saved->proof_image : null,
            'paid_at' => $saved ? $saved->paid_at : null,
        ];
    }

    public function uploadProof(Request $request)
    {
        $partner = auth('partner')->user();
        $hotel = DB::table('hotels')->where('partner_id', $partner->id)->first();

        $request->validate(['proof_image' => 'required|image|max:2048', 'month' => 'required', 'year' => 'required']);

        // TÍNH TOÁN LẠI DỮ LIỆU ĐỂ LƯU CHÍNH XÁC
        $data = $this->calculateData($hotel->id, $request->month, $request->year);

        $existing = DB::table('settlements')
            ->where(['hotel_id' => $hotel->id, 'month' => $request->month, 'year' => $request->year])
            ->first();

        if ($existing && $existing->status == 1) {
            return response()->json(['message' => 'Công nợ đã được duyệt, không thể thay đổi Bill!'], 403);
        }

        $path = $request->file('proof_image')->store('settlements', 'public');

        // CẬP NHẬT ĐẦY ĐỦ CÁC TRƯỜNG DỮ LIỆU
        DB::table('settlements')->updateOrInsert(
            ['hotel_id' => $hotel->id, 'month' => $request->month, 'year' => $request->year],
            [
                'total_revenue'    => $data['total_revenue'],
                'vnpay_total'      => $data['vnpay_total'],
                'commission_total' => $data['commission_total'],
                'payout_to_hotel'  => $data['payout_to_hotel'],
                'proof_image'      => '/storage/' . $path,
                'status'           => 0,
                'created_at'       => $existing ? $existing->created_at : now(),
                'updated_at'       => now()
            ]
        );

        return response()->json(['message' => 'Đã gửi ảnh bill thành công!']);
    }
    public function partnerConfirm(Request $request)
    {
        $request->validate(['month' => 'required', 'year' => 'required']);
        $partner = auth('partner')->user();
        $hotel = DB::table('hotels')->where('partner_id', $partner->id)->first();

        DB::table('settlements')
            ->where(['hotel_id' => $hotel->id, 'month' => $request->month, 'year' => $request->year, 'status' => 2])
            ->update([
                'status' => 1, // 👉 Chuyển thành Đã đối soát hoàn toàn
                'updated_at' => now()
            ]);

        return response()->json(['message' => 'Xác nhận đã nhận tiền thành công!']);
    }
}
