<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Biên Bản Chốt Công Nợ</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 14px; color: #333; line-height: 1.5; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { color: #1e3a8a; margin: 0; font-size: 24px; text-transform: uppercase; }
        .info { margin-bottom: 20px; border-bottom: 1px solid #ccc; padding-bottom: 10px; }
        .table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .table th, .table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .table th { background-color: #f8fafc; color: #1e293b; }
        .text-right { text-align: right !important; }
        .text-danger { color: #dc2626; }
        .text-success { color: #16a34a; }
        .fw-bold { font-weight: bold; }
        .footer { width: 100%; margin-top: 50px; }
        .footer td { text-align: center; width: 50%; vertical-align: bottom; }
        .stamp { color: #dc2626; border: 3px double #dc2626; border-radius: 50%; padding: 15px; font-weight: bold; display: inline-block; transform: rotate(-15deg); margin-top: 20px; }
    </style>
</head>
<body>

    <div class="header">
        <h1>BIÊN BẢN CHỐT CÔNG NỢ VÀ HOA HỒNG</h1>
        <p>Kỳ đối soát: Tháng {{ $month }} / Năm {{ $year }}</p>
        <p style="font-size: 12px; color: #666;">Ngày xuất báo cáo: {{ $export_date }}</p>
    </div>

    <div class="info">
        <p><strong>Bên A (Nền tảng OTA):</strong> STAYBOOK PLATFORM</p>
        <p><strong>Bên B (Khách sạn/Đối tác):</strong> {{ $hotel['hotel_name'] }}</p>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Diễn giải</th>
                <th class="text-right">Số tiền (VNĐ)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1. Tổng doanh thu phát sinh trong tháng</td>
                <td class="text-right fw-bold">{{ number_format($hotel['total_revenue']) }} đ</td>
            </tr>
            <tr>
                <td>2. Tiền Sàn thu hộ (Khách thanh toán qua VNPAY)</td>
                <td class="text-right text-success">{{ number_format($hotel['vnpay_total']) }} đ</td>
            </tr>
            <tr>
                <td>3. Tiền Khách sạn tự thu (Tiền mặt/POS tại quầy)</td>
                <td class="text-right">{{ number_format($hotel['cash_total']) }} đ</td>
            </tr>
            <tr>
                <td>4. Tổng phí hoa hồng Sàn được hưởng (Chiết khấu)</td>
                <td class="text-right text-danger fw-bold">- {{ number_format($hotel['commission_total']) }} đ</td>
            </tr>
            <tr>
                <td style="background-color: #f1f5f9;" class="fw-bold">5. TỔNG CÔNG NỢ CHỐT CUỐI KỲ (Mục 2 - Mục 4)</td>
                <td style="background-color: #f1f5f9;" class="text-right fw-bold text-success">
                    {{ number_format($hotel['payout_to_hotel']) }} đ
                </td>
            </tr>
        </tbody>
    </table>

    <div style="background-color: #fffbeb; border: 1px solid #fde68a; padding: 15px; border-radius: 5px;">
        <p class="fw-bold" style="margin: 0; color: #b45309;">KẾT LUẬN THANH TOÁN:</p>
        <p style="margin: 5px 0 0 0;">
            @if($hotel['payout_to_hotel'] > 0)
                => Sàn StayBook có trách nhiệm chuyển khoản thanh toán cho Khách sạn số tiền là: <strong>{{ number_format($hotel['payout_to_hotel']) }} VNĐ</strong>.
            @elseif($hotel['payout_to_hotel'] < 0)
                => Khách sạn đã tự thu tiền mặt nhiều hơn số tiền hoa hồng. Khách sạn có trách nhiệm hoàn trả lại cho Sàn StayBook số tiền là: <strong class="text-danger">{{ number_format(abs($hotel['payout_to_hotel'])) }} VNĐ</strong>.
            @else
                => Hai bên không phát sinh công nợ cần thanh toán.
            @endif
        </p>
    </div>

    <table class="footer">
        <tr>
            <td>
                <p class="fw-bold">ĐẠI DIỆN KHÁCH SẠN</p>
                <p style="font-size: 12px; color: #888;">(Ký và ghi rõ họ tên)</p>
            </td>
            <td>
                <p class="fw-bold">ĐẠI DIỆN STAYBOOK</p>
                <div class="stamp">
                    ĐÃ XÁC NHẬN<br>STAYBOOK
                </div>
            </td>
        </tr>
    </table>

</body>
</html>