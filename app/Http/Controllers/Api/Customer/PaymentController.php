<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    // 1. TẠO LINK THANH TOÁN GỬI CHO REACT
    public function createVnpayUrl(Request $request)
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'bank_code' => 'nullable|string' // Có thể truyền 'VNBANK' hoặc 'INTCARD'
        ]);

        $booking = Booking::find($request->booking_id);

        if ($booking->payment_status == 1) {
            return response()->json(['message' => 'Đơn hàng này đã được thanh toán'], 400);
        }

        $vnp_TmnCode = env('VNPAY_TMN_CODE');
        $vnp_HashSecret = env('VNPAY_HASH_SECRET');
        $vnp_Url = env('VNPAY_URL');
        $vnp_Returnurl = env('VNPAY_RETURN_URL');

        $vnp_TxnRef = $booking->booking_code . '_' . time(); // Mã giao dịch duy nhất
        $vnp_OrderInfo = "Thanh toan don dat phong " . $booking->booking_code;
        $vnp_OrderType = 'billpayment';
        $vnp_Amount = $booking->total_price * 100; // VNPAY yêu cầu nhân 100
        $vnp_Locale = 'vn';
        $vnp_IpAddr = $request->ip();

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

        // Tạo mã Hash (Chữ ký điện tử) để bảo mật
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
            $vnpSecureHash =   hash_hmac('sha512', $hashdata, $vnp_HashSecret);
            $vnp_Url .= 'vnp_SecureHash=' . $vnpSecureHash;
        }

        return response()->json([
            'message' => 'Tạo link thanh toán thành công',
            'payment_url' => $vnp_Url
        ], 200);
    }

    // 2. NHẬN KẾT QUẢ TỪ VNPAY (IPN WEBHOOK) ĐỂ LƯU DATABASE
    public function vnpayIpn(Request $request)
    {
        $inputData = array();
        $returnData = array();
        foreach ($_GET as $key => $value) {
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

        $vnp_TxnRef = $inputData['vnp_TxnRef']; // Định dạng: SB-XYZ_1699999999
        $bookingCode = explode('_', $vnp_TxnRef)[0];

        try {
            if ($secureHash == $vnp_SecureHash) {
                $booking = Booking::where('booking_code', $bookingCode)->first();

                if ($booking != NULL) {
                    if ($booking->payment_status == 0) {
                        if ($inputData['vnp_ResponseCode'] == '00' && $inputData['vnp_TransactionStatus'] == '00') {

                            DB::beginTransaction();
                            try {
                                // 1. Cập nhật trạng thái đơn hàng: Đã thanh toán & Đã xác nhận (1)
                                $booking->update([
                                    'payment_status' => 1,
                                    'status' => 1
                                ]);

                                // 2. Lưu vào bảng payments
                                Payment::create([
                                    'booking_id' => $booking->id,
                                    'transaction_id' => $inputData['vnp_TransactionNo'], // Lưu mã gd của VNPAY
                                    'payment_method' => 4, // 4 = VNPAY
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
                                $returnData['Message'] = 'Unknown error';
                            }
                        } else {
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
