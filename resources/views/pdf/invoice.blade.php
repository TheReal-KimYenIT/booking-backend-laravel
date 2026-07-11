<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Hóa đơn - {{ $booking->booking_code }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 13px;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
        }

        .hotel-name {
            font-size: 24px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .title {
            font-size: 20px;
            font-weight: bold;
            margin: 20px 0;
            text-align: center;
        }

        .info-table {
            width: 100%;
            margin-bottom: 20px;
        }

        .info-table td {
            padding: 5px 0;
        }

        .bill-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .bill-table th,
        .bill-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: right;
        }

        .bill-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            text-align: center;
        }

        .bill-table .text-left {
            text-align: left;
        }

        .bill-table .text-center {
            text-align: center;
        }

        /* CSS Mới cho phần tổng cộng */
        .bill-table .no-border {
            border: none !important;
        }

        .bill-table .total-label {
            text-align: right;
            padding-right: 15px;
            border: none;
        }

        .bill-table .total-value {
            font-weight: bold;
        }

        .text-red {
            color: #e74c3c;
        }

        .text-green {
            color: #27ae60;
        }

        .highlight-row td {
            border-top: 2px solid #000 !important;
            font-size: 15px;
        }

        .footer {
            text-align: center;
            margin-top: 50px;
            font-size: 12px;
            color: #777;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }
    </style>
</head>

<body>

    <div class="header">
        <div class="hotel-name">{{ $hotel->name ?? 'Khách sạn Đối tác' }}</div>
        <div>Mã đơn hàng: <strong>{{ $booking->booking_code }}</strong></div>
        <div>Ngày in: {{ $print_date }}</div>
    </div>

    <div class="title">HÓA ĐƠN THANH TOÁN (FOLIO)</div>

    <table class="info-table">
        <tr>
            <td><strong>Khách hàng:</strong> {{ $booking->guest_name }}</td>
            <td><strong>Ngày đến:</strong> {{ \Carbon\Carbon::parse($booking->check_in)->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td><strong>Điện thoại:</strong> {{ $booking->guest_phone ?? '---' }}</td>
            <td><strong>Ngày đi:</strong> {{ \Carbon\Carbon::parse($booking->check_out)->format('d/m/Y') }}</td>
        </tr>
    </table>

    <table class="bill-table">
        <thead>
            <tr>
                <th class="text-left">Nội dung / Mặt hàng</th>
                <th>Số lượng</th>
                <th>Đơn giá (VNĐ)</th>
                <th>Thành tiền (VNĐ)</th>
            </tr>
        </thead>
        <tbody>
            <!-- Tiền phòng -->
            <tr>
                <td class="text-left"><strong>Tiền thuê phòng:</strong>
                    {{ $booking->details->first()->roomType->name ?? 'Phòng' }}</td>
                <td class="text-center">
                    {{ $booking->details->first()->rooms_count ?? 1 }} phòng<br>
                    <span style="font-size: 10px; color: #777;">x {{ $nights }} đêm</span>
                </td>
                <td>
                    {{ number_format($roomTotal / max(1, ($booking->details->first()->rooms_count ?? 1) * $nights)) }}
                </td>
                <td>{{ number_format($roomTotal) }}</td>
            </tr>
            <!-- Dịch vụ & Minibar -->
            @foreach ($booking->bookingServices as $item)
                <tr>
                    <td class="text-left">DV/Minibar: {{ $item->service->name ?? 'Dịch vụ' }}</td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td>{{ number_format($item->price_at_booking) }}</td>
                    <td>{{ number_format($item->price_at_booking * $item->quantity) }}</td>
                </tr>
            @endforeach

            <!-- Phụ thu -->
            @foreach ($booking->bookingSurcharges as $item)
                <tr>
                    <td class="text-left">Phụ thu: {{ $item->category->name ?? 'Khác' }} ({{ $item->note }})</td>
                    <td class="text-center">1</td>
                    <td>{{ number_format($item->amount) }}</td>
                    <td>{{ number_format($item->amount) }}</td>
                </tr>
            @endforeach

            <!-- Đền bù -->
            @foreach ($booking->supply_incidents as $item)
                <tr>
                    <td class="text-left">Đền bù: {{ $item->supply->name ?? 'Vật tư' }}</td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td>{{ number_format($item->actual_price) }}</td>
                    <td>{{ number_format($item->actual_price * $item->quantity) }}</td>
                </tr>
            @endforeach

            <!-- ============================================== -->
            <!-- PHẦN TỔNG TIỀN ĐÃ SỬA LẠI ĐỂ DOMPDF KHÔNG BỊ LỖI -->
            <!-- ============================================== -->
            <tr>
                <td colspan="2" class="no-border"></td>
                <td class="total-label">Tổng cộng:</td>
                <td>{{ number_format($roomTotal + $serviceTotal + $surchargeTotal + $damageTotal) }} đ</td>
            </tr>

            @if ($discount > 0)
                <tr>
                    <td colspan="2" class="no-border"></td>
                    <td class="total-label text-red">Chiết khấu:</td>
                    <td class="text-red">- {{ number_format($discount) }} đ</td>
                </tr>
            @endif

            <tr>
                <td colspan="2" class="no-border"></td>
                <td class="total-label">Thuế VAT ({{ $vatRate }}%):</td>
                <td>+ {{ number_format($vatAmount) }} đ</td>
            </tr>

            <tr class="highlight-row">
                <td colspan="2" class="no-border"></td>
                <td class="total-label text-left">TỔNG THANH TOÁN:</td>
                <td class="total-value">{{ number_format($finalTotal) }} đ</td>
            </tr>

            @if ($alreadyPaid > 0)
                <tr>
                    <td colspan="2" class="no-border"></td>
                    <td class="total-label text-green">Đã thanh toán trước:</td>
                    <td class="text-green">- {{ number_format($alreadyPaid) }} đ</td>
                </tr>
                <tr class="highlight-row">
                    <td colspan="2" class="no-border"></td>
                    <td class="total-label {{ $remaining > 0 ? 'text-red' : 'text-green' }}">CÒN LẠI CẦN THU:</td>
                    <td class="total-value {{ $remaining > 0 ? 'text-red' : 'text-green' }}">
                        {{ number_format($remaining) }} đ</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        Cảm ơn Quý khách đã sử dụng dịch vụ của chúng tôi. Hẹn gặp lại!
    </div>
</body>

</html>
