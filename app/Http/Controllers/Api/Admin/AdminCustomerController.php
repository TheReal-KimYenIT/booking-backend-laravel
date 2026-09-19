<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;

class AdminCustomerController extends Controller
{
    /**
     * Lấy danh sách khách hàng
     */
    public function index()
    {
        $customers = Customer::withCount('bookings')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['data' => $customers], 200);
    }

    /**
     * Khóa/Mở khóa tài khoản khách hàng
     */
    public function toggleStatus($id)
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['message' => 'Không tìm thấy khách hàng'], 404);
        }

        $customer->is_active = $customer->is_active == 1 ? 0 : 1;
        $customer->save();

        $statusText = $customer->is_active == 1 ? 'Đã mở khóa tài khoản thành công!' : 'Đã khóa tài khoản khách hàng thành công!';

        return response()->json([
            'message' => $statusText,
            'is_active' => $customer->is_active
        ], 200);
    }
}
