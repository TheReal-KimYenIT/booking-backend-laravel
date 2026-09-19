<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RoomView;

class RoomViewController extends Controller
{
    // 1. GET /api/admin/room-views (Lấy danh sách kèm số lượng phòng liên kết)
    public function index()
    {
        $views = RoomView::withCount('roomTypes')->orderBy('id', 'desc')->get();
        return response()->json(['message' => 'Thành công', 'data' => $views], 200);
    }

    // 2. POST /api/admin/room-views (Tạo mới)
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100|unique:room_views,name',
            'status' => 'nullable|integer|in:0,1'
        ], [
            'name.required' => 'Vui lòng nhập tên hướng nhìn!',
            'name.unique' => 'Hướng nhìn với tên này đã tồn tại trên hệ thống!',
            'name.max' => 'Tên hướng nhìn không được vượt quá 100 ký tự!'
        ]);

        $view = RoomView::create([
            'name' => trim($request->name),
            'status' => isset($request->status) ? (int)$request->status : 1
        ]);
        return response()->json(['message' => 'Thêm hướng nhìn thành công!', 'data' => $view], 201);
    }

    // 3. GET /api/admin/room-views/{id} (Lấy chi tiết 1 cái)
    public function show(int $id)
    {
        $view = RoomView::withCount('roomTypes')->find($id);
        if (!$view) return response()->json(['message' => 'Không tìm thấy hướng nhìn!'], 404);
        return response()->json(['message' => 'Thành công', 'data' => $view], 200);
    }

    // 4. PUT /api/admin/room-views/{id} (Cập nhật)
    public function update(Request $request, int $id)
    {
        $view = RoomView::find($id);
        if (!$view) return response()->json(['message' => 'Không tìm thấy hướng nhìn!'], 404);

        $request->validate([
            'name' => 'required|string|max:100|unique:room_views,name,' . $id,
            'status' => 'nullable|integer|in:0,1'
        ], [
            'name.required' => 'Vui lòng nhập tên hướng nhìn!',
            'name.unique' => 'Hướng nhìn với tên này đã tồn tại trên hệ thống!',
            'name.max' => 'Tên hướng nhìn không được vượt quá 100 ký tự!'
        ]);

        $view->update([
            'name' => trim($request->name),
            'status' => $request->has('status') ? (int)$request->status : $view->status
        ]);
        return response()->json(['message' => 'Cập nhật hướng nhìn thành công!', 'data' => $view], 200);
    }

    // 5. DELETE /api/admin/room-views/{id} (Xóa)
    public function destroy(int $id)
    {
        $view = RoomView::find($id);
        if (!$view) return response()->json(['message' => 'Không tìm thấy hướng nhìn!'], 404);

        // BẢO VỆ ĐỒNG BỘ: Kiểm tra xem có Hạng phòng nào đang dùng hướng nhìn này không
        $inUseCount = \App\Models\RoomType::where('view_id', $id)->count();
        
        if ($inUseCount > 0) {
            return response()->json([
                'message' => "Không thể xóa vì hướng nhìn này đang được liên kết bởi {$inUseCount} hạng phòng!"
            ], 400);
        }

        $view->delete();
        return response()->json(['message' => 'Xóa hướng nhìn thành công!'], 200);
    }
}
