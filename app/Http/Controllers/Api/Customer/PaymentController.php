<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    // Tạo link thanh toán VNPay cho đơn đặt phòng.
    public function createVnpayUrl(Request $request)
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'bank_code' => 'nullable|string'
        ]);

        $booking = Booking::find($request->booking_id);

        if ($booking->payment_status == 1) {
            return response()->json(['message' => 'Đơn đặt phòng này đã được thanh toán'], 400);
        }

        if ($booking->status == 4) {
            return response()->json(['message' => 'Đơn đặt phòng này đã bị hủy, không thể tiếp tục thanh toán.'], 400);
        }

        // Kiểm tra quá hạn 15 phút thanh toán cọc
        if ($booking->created_at && $booking->created_at->lt(now()->subMinutes(15))) {
            \App\Services\BookingCleanupService::cleanupExpiredUnpaid(15);
            return response()->json(['message' => 'Đơn đặt phòng đã hết hạn thanh toán (quá 15 phút). Vui lòng đặt lại phòng mới.'], 400);
        }

        if (str_starts_with($booking->booking_code, 'BK')) {
            $newBookingCode = 'SB' . substr($booking->booking_code, 2);

            // Cập nhật lại mã mới vào DB để đồng bộ
            $booking->update(['booking_code' => $newBookingCode]);
            $booking->booking_code = $newBookingCode;
        }

        $vnp_TmnCode = env('VNPAY_TMN_CODE');
        $vnp_HashSecret = env('VNPAY_HASH_SECRET');
        $vnp_Url = env('VNPAY_URL');
        $vnp_Returnurl = env('VNPAY_RETURN_URL');

        $vnp_TxnRef = $booking->booking_code . '_' . time();

        $vnp_OrderInfo = "Thanh toan don dat phong " . $booking->booking_code;
        $vnp_OrderType = 'billpayment';
        $vnp_Amount = $booking->deposit_amount * 100;
        $vnp_Locale = 'vn';
        $vnp_IpAddr = $request->ip();
        if (empty($vnp_IpAddr) || $vnp_IpAddr === '::1') {
            $vnp_IpAddr = '127.0.0.1';
        }

        $inputData = array(
            "vnp_Version" => "2.1.0",
            "vnp_TmnCode" => $vnp_TmnCode,
            "vnp_Amount" => $vnp_Amount,
            "vnp_Command" => "pay",
            "vnp_CreateDate" => date('YmdHis'),
            "vnp_CurrCode" => "VND",
            "vnp_IpAddr" => $vnp_IpAddr,
            "vnp_Locale" => $vnp_Locale,
            "vnp_OrderInfo" => $vnp_OrderInfo,
            "vnp_OrderType" => $vnp_OrderType,
            "vnp_ReturnUrl" => $vnp_Returnurl,
            "vnp_TxnRef" => $vnp_TxnRef,
        );

        if ($request->filled('bank_code')) {
            $inputData['vnp_BankCode'] = $request->bank_code;
        }

        ksort($inputData);
        $query = "";
        $i = 0;
        $hashdata = "";
        foreach ($inputData as $key => $value) {
            if ($i == 1) {
                $hashdata .= '&' . urlencode($key) . "=" . urlencode($value);
            } else {
                $hashdata .= urlencode($key) . "=" . urlencode($value);
                $i = 1;
            }
            $query .= urlencode($key) . "=" . urlencode($value) . '&';
        }

        $vnp_Url = $vnp_Url . "?" . $query;
        if (isset($vnp_HashSecret)) {
            $vnpSecureHash = hash_hmac('sha512', $hashdata, $vnp_HashSecret);
            $vnp_Url .= 'vnp_SecureHash=' . $vnpSecureHash;
        }

        return response()->json([
            'message' => 'Tạo link thanh toán thành công',
            'payment_url' => $vnp_Url
        ], 200);
    }

    // Nhận kết quả thanh toán từ VNPay và cập nhật trạng thái đơn hàng.
    public function vnpayIpn(Request $request)
    {
        $inputData = array();
        $returnData = array();
        $allRequestData = $request->all();

        foreach ($allRequestData as $key => $value) {
            if (substr($key, 0, 4) == "vnp_") {
                $inputData[$key] = $value;
            }
        }

        $vnp_SecureHash = $inputData['vnp_SecureHash'];
        unset($inputData['vnp_SecureHash']);
        ksort($inputData);
        $i = 0;
        $hashData = "";
        foreach ($inputData as $key => $value) {
            if ($i == 1) {
                $hashData = $hashData . '&' . urlencode($key) . "=" . urlencode($value);
            } else {
                $hashData = $hashData . urlencode($key) . "=" . urlencode($value);
                $i = 1;
            }
        }

        $vnp_HashSecret = env('VNPAY_HASH_SECRET');
        $secureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);

        $vnp_TxnRef = $inputData['vnp_TxnRef'];

        $parts = explode('_', $vnp_TxnRef);
        $bookingCode = $parts[0];

        // Kiểm tra an toàn: Mã phải bắt đầu bằng BK hoặc SB
        if (!str_starts_with($bookingCode, 'BK') && !str_starts_with($bookingCode, 'SB')) {
            return response()->json(['RspCode' => '01', 'Message' => 'Invalid Order Prefix']);
        }

        try {
            if ($secureHash == $vnp_SecureHash) {
                $booking = Booking::where('booking_code', $bookingCode)->first();

                if ($booking != NULL) {
                    if ($booking->payment_status == 0) {
                        if ($inputData['vnp_ResponseCode'] == '00' && $inputData['vnp_TransactionStatus'] == '00') {

                            DB::beginTransaction();
                            try {
                                $booking->update([
                                    'payment_status' => 1,
                                    'status' => 1
                                ]);

                                Payment::create([
                                    'booking_id' => $booking->id,
                                    'transaction_id' => $inputData['vnp_TransactionNo'],
                                    'payment_method' => 4, // 4 là mã quy ước cho VNPAY/Online
                                    'amount' => $inputData['vnp_Amount'] / 100,
                                    'payment_status' => 1,
                                    'paid_at' => now(),
                                ]);
                                DB::commit();
                                $returnData['RspCode'] = '00';
                                $returnData['Message'] = 'Confirm Success';
                            } catch (\Exception $e) {
                                DB::rollBack();
                                $returnData['RspCode'] = '99';
                                $returnData['Message'] = 'Database Error';
                            }
                        } else {
                            // Giao dịch không thành công từ VNPAY (khách hủy, lỗi thẻ...)
                            $returnData['RspCode'] = '00';
                            $returnData['Message'] = 'Payment Failed';
                        }
                    } else {
                        $returnData['RspCode'] = '02';
                        $returnData['Message'] = 'Order already confirmed';
                    }
                } else {
                    $returnData['RspCode'] = '01';
                    $returnData['Message'] = 'Order not found';
                }
            } else {
                $returnData['RspCode'] = '97';
                $returnData['Message'] = 'Invalid signature';
            }
        } catch (\Exception $e) {
            $returnData['RspCode'] = '99';
            $returnData['Message'] = 'Unknown error';
        }

        return response()->json($returnData);
    }
}
