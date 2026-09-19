<?php

namespace App\Http\Controllers\Api\PublicArea;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Partner;
use App\Models\Customer;
use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    // Đăng ký tài khoản khách hàng mới.
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|string|email|max:100|unique:customers,email',
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/'],
        ], [
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự.',
            'password.regex' => 'Mật khẩu phải chứa ít nhất 1 chữ hoa, 1 chữ thường và 1 số.',
        ]);

        $nameParts = explode(' ', trim($request->name));
        $firstName = array_pop($nameParts);
        $lastName = empty($nameParts) ? $firstName : implode(' ', $nameParts);

        $customer = Customer::create([
            'last_name' => $lastName,
            'first_name' => $firstName,
            'email' => $request->email,
            'password_hash' => Hash::make($request->password),
            'is_active' => 1,
            'created_at' => now()
        ]);

        $token = $customer->createToken('customer_token')->plainTextToken;

        return response()->json([
            'message' => 'Đăng ký tài khoản khách hàng thành công!',
            'user' => $customer,
            'token' => $token,
            'role' => 'customer'
        ], 201);
    }

    // Đăng ký tài khoản đối tác khách sạn cho bên quản lý khách sạn.
    public function registerPartner(Request $request)
    {
        $request->validate([
            'last_name' => 'required|string|max:100',
            'first_name' => 'required|string|max:50',
            'email' => 'required|string|email|unique:partners,email',
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/'], // Bắt buộc trùng khớp mật khẩu
            'phone' => 'required|string|regex:/^[0-9]{9,15}$/', // Bắt buộc và đúng định dạng số
            'hotel_name' => 'required|string|max:255',
            'tax_code' => 'required|string|regex:/^[0-9]{10}$/', // Bắt buộc đúng 10 chữ số
            'city' => 'required|string|max:100',
            'address' => 'required|string|max:255',
            'business_license' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ], [
            // Tùy chỉnh (Tùy chọn) câu thông báo lỗi cho rõ ràng
            'tax_code.regex' => 'Mã số thuế phải bao gồm đúng 10 chữ số.',
            'phone.regex' => 'Số điện thoại không hợp lệ.',
            'business_license.image' => 'Giấy phép kinh doanh phải là file hình ảnh hợp lệ.',
        ]);

        DB::beginTransaction();
        try {
            $partner = Partner::create([
                'role_id' => 1, // ĐÃ SỬA: Cấp cứng quyền 1 (Chủ khách sạn) thay vì để DB gán mặc định là 10
                'last_name' => $request->last_name,
                'first_name' => $request->first_name,
                'email' => $request->email,
                'password_hash' => Hash::make($request->password),
                'phone' => $request->phone,
                'is_active' => 0, // Mặc định chờ duyệt
            ]);

            $licenseUrl = null;
            if ($request->hasFile('business_license')) {
                $file = $request->file('business_license');
                // Tạo tên file ngẫu nhiên để không bị trùng lặp
                $filename = time() . '_' . $file->getClientOriginalName();
                // Lưu vào thư mục storage/app/public/uploads/licenses
                $path = $file->storeAs('uploads/licenses', $filename, 'public');
                // Gán đường dẫn URL để Frontend có thể hiển thị
                $licenseUrl = '/storage/' . $path;
            }

            Hotel::create([
                'partner_id' => $partner->id,
                'name' => $request->hotel_name,
                'city' => $request->city,
                'address' => $request->address,
                'tax_code' => $request->tax_code, // LƯU VÀO CƠ SỞ DỮ LIỆU
                'business_license_url' => $licenseUrl,
                'status' => 0,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Đăng ký thành công! Vui lòng chờ Admin phê duyệt.'
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Lỗi quá trình đăng ký: ' . $e->getMessage()
            ], 500);
        }
    }

    // Đăng nhập cho admin, đối tác hoặc khách hàng.
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'type' => 'required|in:admin,partner,customer'
        ]);

        // Cú pháp match (PHP 8+) siêu gọn gàng
        $model = match ($request->type) {
            'admin' => Admin::class,
            'partner' => Partner::class,
            'customer' => Customer::class,
        };

        $query = $model::where('email', $request->email);
        
        // Eager load role if the model is Partner to get permissions
        if ($request->type === 'partner') {
            $query->with('role');
        }

        $user = $query->first();

        // Kiểm tra thủ công do dùng cột password_hash
        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            return response()->json([
                'message' => 'Email hoặc mật khẩu không chính xác!'
            ], 401);
        }

        // Khách hàng không cần duyệt, chỉ kiểm tra is_active cho admin/partner
        if (isset($user->is_active) && $user->is_active == 0) {
            return response()->json([
                'message' => 'Tài khoản của bạn đã bị khóa hoặc đang chờ duyệt!'
            ], 403);
        }

        // Tạo Token Sanctum
        $token = $user->createToken($request->type . '_token')->plainTextToken;

        return response()->json([
            'message' => 'Đăng nhập thành công!',
            'user' => $user,
            'token' => $token,
            'role' => $request->type
        ], 200);
    }

    // Đăng xuất và hủy token hiện tại.
    public function logout(Request $request)
    {
        // Xóa Token hiện tại đang được sử dụng để gọi API này
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Đăng xuất thành công!'
        ], 200);
    }
}
