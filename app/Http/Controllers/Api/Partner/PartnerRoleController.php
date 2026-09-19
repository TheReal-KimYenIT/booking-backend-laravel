<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\PartnerRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PartnerRoleController extends Controller
{
    private function checkIsOwner()
    {
        $user = Auth::guard('partner')->user();
        return $user && $user->role_id == 1; // Chỉ Chủ KS mới được thao tác
    }
    public function index()
    {
        if (!$this->checkIsOwner()) return response()->json(['message' => 'Từ chối truy cập!'], 403);

        $roles = PartnerRole::where('owner_id', $this->getOwnerId())
            ->withCount('staffs')
            ->orderBy('created_at', 'desc')->get();
        return response()->json(['data' => $roles], 200);
    }

    public function store(Request $request)
    {
        if (!$this->checkIsOwner()) return response()->json(['message' => 'Từ chối truy cập!'], 403);

        $request->validate([
            'name' => 'required|string|max:100',
            'permissions' => 'required|array'
        ]);

        $role = PartnerRole::create([
            'owner_id' => $this->getOwnerId(),
            'name' => $request->name,
            'permissions' => $request->permissions // Sẽ tự parse thành JSON nhờ file Model
        ]);

        return response()->json(['message' => 'Tạo nhóm quyền thành công!', 'data' => $role], 201);
    }

    public function update(Request $request, int $id)
    {
        if (!$this->checkIsOwner()) return response()->json(['message' => 'Từ chối truy cập!'], 403);

        $request->validate([
            'name' => 'required|string|max:100',
            'permissions' => 'required|array'
        ]);

        $role = PartnerRole::where('id', $id)->where('owner_id', $this->getOwnerId())->firstOrFail();
        $role->update([
            'name' => $request->name,
            'permissions' => $request->permissions
        ]);

        return response()->json(['message' => 'Cập nhật thành công!'], 200);
    }

    public function destroy(int $id)
    {
        if (!$this->checkIsOwner()) return response()->json(['message' => 'Từ chối truy cập!'], 403);

        $role = PartnerRole::where('id', $id)->where('owner_id', $this->getOwnerId())->firstOrFail();

        // Kiểm tra xem có nhân viên nào đang dùng nhóm này không
        if ($role->staffs()->count() > 0) {
            return response()->json(['message' => 'Không thể xóa! Có nhân viên đang thuộc nhóm này.'], 400);
        }

        $role->delete();
        return response()->json(['message' => 'Đã xóa nhóm quyền!'], 200);
    }
}
