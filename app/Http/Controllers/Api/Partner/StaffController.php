<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    private function checkIsOwner()
    {
        $user = Auth::guard('partner')->user();
        return $user && $user->role_id == 1; // 1 luôn là Owner
    }

    public function index()
    {
        if (!$this->checkIsOwner()) return response()->json(['message' => 'Không có quyền truy cập!'], 403);

        $ownerId = $this->getOwnerId();

        // Kéo theo thông tin tên Nhóm quyền (role.name, permissions)
        $staffs = Partner::with('role:id,name,permissions')
            ->where('parent_id', $ownerId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['message' => 'Thành công', 'data' => $staffs], 200);
    }

    public function store(Request $request)
    {
        if (!$this->checkIsOwner()) return response()->json(['message' => 'Không có quyền truy cập!'], 403);

        $request->validate([
            'first_name' => 'required|string|max:50',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|unique:partners,email',
            'password' => ['required', 'string', 'min:8', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/'],
            'phone' => 'nullable|string|max:15',
            'role_id' => 'required|integer|exists:partner_roles,id',
        ], [
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự.',
            'password.regex' => 'Mật khẩu phải chứa ít nhất 1 chữ hoa, 1 chữ thường và 1 số.',
            'email.unique' => 'Email này đã tồn tại trong hệ thống.',
            'email.email' => 'Định dạng email không hợp lệ.',
        ]);

        $ownerId = $this->getOwnerId();

        // Đảm bảo role_id được chọn thuộc về đúng Owner này
        $roleExists = \App\Models\PartnerRole::where('id', $request->role_id)->where('owner_id', $ownerId)->exists();
        if (!$roleExists) return response()->json(['message' => 'Nhóm quyền không hợp lệ!'], 400);

        $staff = Partner::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'password_hash' => Hash::make($request->password),
            'phone' => $request->phone,
            'role_id' => $request->role_id,
            'parent_id' => $ownerId,
            'is_active' => $request->has('is_active') ? (int)$request->is_active : 1
        ]);

        $staff->load('role:id,name,permissions');

        return response()->json(['message' => 'Tạo tài khoản nhân viên thành công!', 'data' => $staff], 201);
    }

    public function update(Request $request, int $id)
    {
        if (!$this->checkIsOwner()) return response()->json(['message' => 'Không có quyền truy cập!'], 403);

        $ownerId = $this->getOwnerId();
        $staff = Partner::where('id', $id)->where('parent_id', $ownerId)->firstOrFail();

        $rules = [
            'first_name' => 'required|string|max:50',
            'last_name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:15',
            'role_id' => 'required|integer|exists:partner_roles,id',
            'is_active' => 'required|boolean'
        ];

        $messages = [
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự.',
            'password.regex' => 'Mật khẩu phải chứa ít nhất 1 chữ hoa, 1 chữ thường và 1 số.',
        ];

        if ($request->filled('password')) {
            $rules['password'] = ['string', 'min:8', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/'];
        }

        $request->validate($rules, $messages);

        // Đảm bảo role_id được chọn thuộc về đúng Owner này
        $roleExists = \App\Models\PartnerRole::where('id', $request->role_id)->where('owner_id', $ownerId)->exists();
        if (!$roleExists) return response()->json(['message' => 'Nhóm quyền không hợp lệ!'], 400);

        $staff->first_name = $request->first_name;
        $staff->last_name = $request->last_name;
        $staff->phone = $request->phone;
        $staff->role_id = $request->role_id;
        $staff->is_active = (int)$request->is_active;

        if ($request->filled('password')) {
            $staff->password_hash = Hash::make($request->password);
        }

        $staff->save();
        $staff->load('role:id,name,permissions');

        return response()->json(['message' => 'Cập nhật thông tin nhân viên thành công!', 'data' => $staff], 200);
    }

    public function toggleStatus(int $id)
    {
        if (!$this->checkIsOwner()) return response()->json(['message' => 'Không có quyền truy cập!'], 403);

        $ownerId = $this->getOwnerId();
        $staff = Partner::where('id', $id)->where('parent_id', $ownerId)->firstOrFail();
        $staff->is_active = $staff->is_active ? 0 : 1;
        $staff->save();
        $staff->load('role:id,name,permissions');

        $statusLabel = $staff->is_active ? 'Đã kích hoạt tài khoản nhân viên!' : 'Đã tạm khóa tài khoản nhân viên!';

        return response()->json([
            'message' => $statusLabel,
            'data' => $staff
        ], 200);
    }

    public function destroy(int $id)
    {
        if (!$this->checkIsOwner()) return response()->json(['message' => 'Không có quyền truy cập!'], 403);
        $ownerId = $this->getOwnerId();
        $staff = Partner::where('id', $id)->where('parent_id', $ownerId)->firstOrFail();
        $staff->delete();
        return response()->json(['message' => 'Đã xóa tài khoản nhân viên!'], 200);
    }

    // Thêm hàm lấy danh sách Role để đổ vào Dropdown ở Frontend
    public function getRoles()
    {
        if (!$this->checkIsOwner()) return response()->json(['message' => 'Không có quyền truy cập!'], 403);

        $ownerId = $this->getOwnerId();
        $roles = \App\Models\PartnerRole::where('owner_id', $ownerId)->withCount('staffs')->get();

        return response()->json(['data' => $roles], 200);
    }
}
