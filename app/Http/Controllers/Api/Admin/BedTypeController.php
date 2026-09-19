<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BedType;

class BedTypeController extends Controller
{
    // Lấy danh sách loại giường để admin quản lý kèm số lượng phòng liên kết.
    public function index()
    {
        $beds = BedType::withCount('roomTypes')->orderBy('id', 'desc')->get();
        return response()->json(['message' => 'Thành công', 'data' => $beds], 200);
    }

    // Tạo mới một loại giường.
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100|unique:bed_types,name',
            'status' => 'nullable|integer|in:0,1'
        ], [
            'name.required' => 'Vui lòng nhập tên loại giường!',
            'name.unique' => 'Loại giường với tên này đã tồn tại trên hệ thống!',
            'name.max' => 'Tên loại giường không được vượt quá 100 ký tự!'
        ]);

        $bed = BedType::create([
            'name' => trim($request->name),
            'status' => isset($request->status) ? (int)$request->status : 1
        ]);
        return response()->json(['message' => 'Thêm loại giường thành công!', 'data' => $bed], 201);
    }

    // Xem chi tiết một loại giường.
    public function show(int $id)
    {
        $bed = BedType::withCount('roomTypes')->find($id);
        if (!$bed) return response()->json(['message' => 'Không tìm thấy loại giường!'], 404);
        return response()->json(['message' => 'Thành công', 'data' => $bed], 200);
    }

    // Cập nhật loại giường đã có.
    public function update(Request $request, int $id)
    {
        $bed = BedType::find($id);
        if (!$bed) return response()->json(['message' => 'Không tìm thấy loại giường!'], 404);

        $request->validate([
            'name' => 'required|string|max:100|unique:bed_types,name,' . $id,
            'status' => 'nullable|integer|in:0,1'
        ], [
            'name.required' => 'Vui lòng nhập tên loại giường!',
            'name.unique' => 'Loại giường với tên này đã tồn tại trên hệ thống!',
            'name.max' => 'Tên loại giường không được vượt quá 100 ký tự!'
        ]);

        $bed->update([
            'name' => trim($request->name),
            'status' => $request->has('status') ? (int)$request->status : $bed->status
        ]);
        return response()->json(['message' => 'Cập nhật loại giường thành công!', 'data' => $bed], 200);
    }

    // Xóa loại giường nếu không còn dùng.
    public function destroy(int $id)
    {
        $bed = BedType::find($id);
        if (!$bed) return response()->json(['message' => 'Không tìm thấy loại giường!'], 404);

        // BẢO VỆ ĐỒNG BỘ: Chặn xóa nếu có Hạng phòng đang dùng
        $inUseCount = \App\Models\RoomType::where('bed_type_id', $id)->count();
        if ($inUseCount > 0) {
            return response()->json([
                'message' => "Không thể xóa vì loại giường này đang được liên kết bởi {$inUseCount} hạng phòng!"
            ], 400);
        }

        $bed->delete();
        return response()->json(['message' => 'Xóa loại giường thành công!'], 200);
    }
}
