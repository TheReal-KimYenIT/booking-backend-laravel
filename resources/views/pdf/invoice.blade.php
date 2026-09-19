<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Hóa đơn - {{ $booking->booking_code }}</title>
    <style>
        @page {
            margin: 20px 25px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #1e293b;
            line-height: 1.4;
        }

        /* HEADER */
        .header-table {
            width: 100%;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 12px;
            margin-bottom: 15px;
        }

        .hotel-name {
            font-size: 18px;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .hotel-info {
            font-size: 10.5px;
            color: #475569;
            line-height: 1.35;
        }

        .invoice-title-block {
            text-align: right;
            vertical-align: top;
        }

        .invoice-title {
            font-size: 18px;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .invoice-meta {
            font-size: 10.5px;
            color: #475569;
            margin-top: 4px;
        }

        /* THÔNG TIN KHÁCH & ĐẶT PHÒNG */
        .info-card {
            width: 100%;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            margin-bottom: 16px;
        }

        .info-table {
            width: 100%;
            padding: 8px 12px;
        }

        .info-table td {
            padding: 3px 6px;
            font-size: 11px;
            vertical-align: top;
        }

        .info-label {
            color: #64748b;
            width: 110px;
        }

        .info-val {
            font-weight: 600;
            color: #0f172a;
        }

        /* BẢNG CHI TIẾT DỊCH VỤ */
        .bill-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }

        .bill-table th {
            background-color: #f1f5f9;
            color: #0f172a;
            font-weight: bold;
            font-size: 10.5px;
            border: 1px solid #cbd5e1;
            padding: 7px 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .bill-table td {
            border: 1px solid #e2e8f0;
            padding: 7px 8px;
            font-size: 10.5px;
            vertical-align: middle;
        }

        .text-left { text-align: left; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }

        .item-title {
            font-weight: 600;
            color: #0f172a;
        }

        .item-sub {
            font-size: 9.5px;
            color: #64748b;
            margin-top: 1px;
        }

        /* PHẦN TỔNG KẾT */
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .summary-table td {
            padding: 4px 8px;
            font-size: 11px;
        }

        .sum-label {
            text-align: right;
            color: #475569;
            width: 65%;
            padding-right: 15px;
        }

        .sum-val {
            text-align: right;
            font-weight: 600;
            width: 35%;
            color: #0f172a;
            white-space: nowrap;
        }

        .row-grand-total td {
            border-top: 2px solid #0f172a;
            border-bottom: 1px solid #0f172a;
            padding: 8px 8px;
            font-size: 12px;
            font-weight: bold;
            color: #0f172a;
        }

        .row-remaining td {
            border-top: 1px dashed #cbd5e1;
            padding: 7px 8px;
            font-size: 13px;
            font-weight: bold;
        }

        .text-emerald { color: #059669; }
        .text-rose { color: #e11d48; }
        .text-blue { color: #1d4ed8; }

        /* CHỮ KÝ */
        .signature-table {
            width: 100%;
            margin-top: 15px;
            page-break-inside: avoid;
        }

        .signature-box {
            text-align: center;
            width: 50%;
            vertical-align: top;
        }

        .sig-title {
            font-weight: bold;
            font-size: 11px;
            color: #0f172a;
            text-transform: uppercase;
        }

        .sig-sub {
            font-size: 9.5px;
            color: #64748b;
            font-style: italic;
            margin-top: 2px;
        }

        .sig-space {
            height: 55px;
        }

        .sig-name {
            font-weight: bold;
            font-size: 11px;
            color: #0f172a;
        }

        /* FOOTER */
        .footer {
            text-align: center;
            margin-top: 20px;
            font-size: 9.5px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
            font-style: italic;
        }
    </style>
</head>

<body>

    <!-- 1. HEADER KHÁCH SẠN & THÔNG TIN HÓA ĐƠN -->
    <table class="header-table">
        <tr>
            <td style="width: 60%; vertical-align: top;">
                <div class="hotel-name">{{ $hotel->name ?? 'KHÁCH SẠN ĐỐI TÁC' }}</div>
                <div class="hotel-info">
                    @if (!empty($hotel->address))
                        <div>Địa chỉ: {{ $hotel->address }}{{ !empty($hotel->city) ? ', ' . $hotel->city : '' }}</div>
                    @endif
                    @if (!empty($hotel->partner->phone) || !empty($hotel->partner->email))
                        <div>
                            @if(!empty($hotel->partner->phone)) Hotline: {{ $hotel->partner->phone }} @endif
                            @if(!empty($hotel->partner->phone) && !empty($hotel->partner->email)) | @endif
                            @if(!empty($hotel->partner->email)) Email: {{ $hotel->partner->email }} @endif
                        </div>
                    @endif
                    @if (!empty($hotel->tax_code))
                        <div>Mã số thuế (MST): {{ $hotel->tax_code }}</div>
                    @endif
                </div>
            </td>
            <td class="invoice-title-block" style="width: 40%;">
                <div class="invoice-title">HÓA ĐƠN THANH TOÁN</div>
                <div class="invoice-meta">
                    <div>Mã đơn: <strong style="color: #0f172a; font-family: monospace; font-size: 12px;">{{ $booking->booking_code }}</strong></div>
                    <div>Ngày in: {{ $print_date }}</div>
                    <div>Thu ngân: {{ auth('partner')->user()->full_name ?? auth('partner')->user()->first_name ?? 'Lễ tân' }}</div>
                </div>
            </td>
        </tr>
    </table>

    <!-- 2. THÔNG TIN KHÁCH HÀNG & LƯU TRÚ -->
    <div class="info-card">
        <table class="info-table">
            <tr>
                <td style="width: 50%;">
                    <table style="width: 100%;">
                        <tr>
                            <td class="info-label">Khách hàng:</td>
                            <td class="info-val">{{ $booking->guest_name }}</td>
                        </tr>
                        <tr>
                            <td class="info-label">Số điện thoại:</td>
                            <td class="info-val">{{ $booking->guest_phone ?? 'Chưa cung cấp' }}</td>
                        </tr>
                        <tr>
                            <td class="info-label">Phòng vật lý:</td>
                            <td class="info-val" style="color: #1d4ed8;">{{ $assignedRooms ?? 'Chờ xếp phòng' }}</td>
                        </tr>
                    </table>
                </td>
                <td style="width: 50%;">
                    <table style="width: 100%;">
                        <tr>
                            <td class="info-label">Ngày nhận phòng:</td>
                            <td class="info-val">
                                {{ \Carbon\Carbon::parse($booking->check_in)->format('d/m/Y') }} (14:00)
                            </td>
                        </tr>
                        <tr>
                            <td class="info-label">Ngày trả phòng:</td>
                            <td class="info-val">
                                {{ \Carbon\Carbon::parse($booking->check_out)->format('d/m/Y') }} (12:00)
                            </td>
                        </tr>
                        <tr>
                            <td class="info-label">Thời gian lưu trú:</td>
                            <td class="info-val">
                                <span style="background-color: #e2e8f0; padding: 1px 6px; border-radius: 4px;">{{ $nights }} đêm</span>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <!-- 3. BẢNG CHI TIẾT CÁC KHOẢN PHÍ -->
    <table class="bill-table">
        <thead>
            <tr>
                <th class="text-left" style="width: 46%;">Nội dung / Mặt hàng</th>
                <th class="text-center" style="width: 16%;">Số lượng</th>
                <th class="text-right" style="width: 19%;">Đơn giá (VNĐ)</th>
                <th class="text-right" style="width: 19%;">Thành tiền (VNĐ)</th>
            </tr>
        </thead>
        <tbody>
            <!-- Tiền phòng -->
            @foreach ($booking->details as $d)
                <tr>
                    <td class="text-left">
                        <div class="item-title">Tiền thuê phòng: {{ $d->roomType->name ?? 'Phòng nghỉ' }}</div>
                    </td>
                    <td class="text-center">
                        {{ $d->rooms_count ?? 1 }} phòng<br>
                        <span class="item-sub">x {{ $nights }} đêm</span>
                    </td>
                    <td class="text-right">
                        {{ number_format(round($d->subtotal / max(1, ($d->rooms_count ?? 1) * $nights))) }}
                    </td>
                    <td class="text-right font-bold" style="font-weight: 600;">
                        {{ number_format($d->subtotal) }}
                    </td>
                </tr>
            @endforeach

            <!-- Dịch vụ gia tăng -->
            @foreach ($booking->bookingServices as $item)
                <tr>
                    <td class="text-left">
                        <div class="item-title">Dịch vụ: {{ $item->service->name ?? 'Dịch vụ' }}</div>
                        @if(!empty($item->note))
                            <div class="item-sub">Ghi chú: {{ $item->note }}</div>
                        @endif
                    </td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td class="text-right">{{ number_format($item->price_at_booking) }}</td>
                    <td class="text-right" style="font-weight: 600;">{{ number_format($item->price_at_booking * $item->quantity) }}</td>
                </tr>
            @endforeach

            <!-- Phụ thu phát sinh -->
            @foreach ($booking->bookingSurcharges as $item)
                <tr>
                    <td class="text-left">
                        <div class="item-title">Phụ thu: {{ $item->category->name ?? 'Phụ thu' }}</div>
                        @if(!empty($item->note))
                            <div class="item-sub">Lý do: {{ $item->note }}</div>
                        @endif
                    </td>
                    <td class="text-center">1</td>
                    <td class="text-right">{{ number_format($item->amount) }}</td>
                    <td class="text-right" style="font-weight: 600;">{{ number_format($item->amount) }}</td>
                </tr>
            @endforeach

            <!-- Đền bù vật tư / tài sản -->
            @foreach ($booking->supply_incidents as $item)
                <tr>
                    <td class="text-left">
                        <div class="item-title">Đền bù tài sản: {{ $item->supply->name ?? 'Vật tư' }}</div>
                        @if(!empty($item->reason))
                            <div class="item-sub">Lý do: {{ $item->reason }}</div>
                        @endif
                    </td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td class="text-right">{{ number_format($item->supply->price_per_unit ?? round($item->actual_price / max(1, $item->quantity))) }}</td>
                    <td class="text-right" style="font-weight: 600; color: #e11d48;">{{ number_format($item->actual_price) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- 4. PHẦN TỔNG HỢP VÀ THANH TOÁN -->
    <table class="summary-table">
        <tr>
            <td class="sum-label">Tổng tiền phòng, dịch vụ & phụ phí:</td>
            <td class="sum-val">{{ number_format($roomTotal + $serviceTotal + $surchargeTotal + $damageTotal) }} đ</td>
        </tr>

        @if ($discount > 0)
            <tr>
                <td class="sum-label text-rose">Chiết khấu / Khuyến mại (Voucher):</td>
                <td class="sum-val text-rose">- {{ number_format($discount) }} đ</td>
            </tr>
        @endif

        <tr>
            <td class="sum-label">Thuế VAT ({{ $vatRate }}%):</td>
            <td class="sum-val">+ {{ number_format($vatAmount) }} đ</td>
        </tr>

        <tr class="row-grand-total">
            <td class="sum-label" style="color: #0f172a;">TỔNG CỘNG HÓA ĐƠN:</td>
            <td class="sum-val" style="font-size: 13px;">{{ number_format($finalTotal) }} đ</td>
        </tr>

        <!-- THÔNG TIN THANH TOÁN THEO TRẠNG THÁI -->
        @if ($booking->status == 3)
            @if ($depositAmount > 0)
                <tr>
                    <td class="sum-label text-blue">Đã thanh toán cọc trực tuyến (VNPay):</td>
                    <td class="sum-val text-blue">- {{ number_format($depositAmount) }} đ</td>
                </tr>
            @endif
            @if ($paidAtCheckout > 0)
                <tr>
                    <td class="sum-label text-blue">Đã thanh toán khi trả phòng:</td>
                    <td class="sum-val text-blue">- {{ number_format($paidAtCheckout) }} đ</td>
                </tr>
            @endif
            <tr class="row-remaining">
                <td class="sum-label text-emerald">CÒN LẠI:</td>
                <td class="sum-val text-emerald">0 đ (ĐÃ HOÀN TẤT THANH TOÁN)</td>
            </tr>
        @elseif ($booking->status == 2)
            @if ($depositAmount > 0)
                <tr>
                    <td class="sum-label text-blue">Đã thanh toán cọc (VNPay):</td>
                    <td class="sum-val text-blue">- {{ number_format($depositAmount) }} đ</td>
                </tr>
            @endif
            <tr class="row-remaining">
                <td class="sum-label {{ $remaining > 0 ? 'text-rose' : 'text-emerald' }}">CÒN LẠI CẦN THANH TOÁN KHI TRẢ PHÒNG:</td>
                <td class="sum-val {{ $remaining > 0 ? 'text-rose' : 'text-emerald' }}">{{ number_format($remaining) }} đ</td>
            </tr>
        @elseif ($booking->status == 4)
            <tr class="row-remaining">
                <td class="sum-label text-rose">TRẠNG THÁI:</td>
                <td class="sum-val text-rose">ĐƠN ĐÃ HỦY</td>
            </tr>
        @elseif ($booking->status == 5)
            <tr class="row-remaining">
                <td class="sum-label" style="color: #b45309;">TRẠNG THÁI:</td>
                <td class="sum-val" style="color: #b45309;">KHÁCH KHÔNG ĐẾN (NO-SHOW)</td>
            </tr>
        @endif
    </table>

    <!-- 5. CHỮ KÝ XÁC NHẬN -->
    <table class="signature-table">
        <tr>
            <td class="signature-box">
                <div class="sig-title">Khách hàng</div>
                <div class="sig-sub">(Ký và ghi rõ họ tên)</div>
                <div class="sig-space"></div>
                <div class="sig-name">{{ $booking->guest_name }}</div>
            </td>
            <td class="signature-box">
                <div class="sig-title">Đại diện Khách sạn / Thu ngân</div>
                <div class="sig-sub">(Ký, đóng dấu và ghi rõ họ tên)</div>
                <div class="sig-space"></div>
                <div class="sig-name">{{ $hotel->name ?? 'Bộ phận Lễ tân' }}</div>
            </td>
        </tr>
    </table>

    <!-- 6. FOOTER -->
    <div class="footer">
        Cảm ơn Quý khách đã lựa chọn lưu trú tại {{ $hotel->name ?? 'khách sạn của chúng tôi' }}! Chúc Quý khách một chuyến đi vui vẻ và bình an.
    </div>

</body>

</html>
