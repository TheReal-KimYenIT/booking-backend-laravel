<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\Media;
use App\Models\Amenity;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class RoomTypeController extends Controller
{
    // Use Case 1: Xem danh sách loại phòng
    public function index(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        $roomTypes = RoomType::with(['amenities', 'media', 'roomView', 'bedTypeDetail'])
            ->where('hotel_id', $hotelId)
            ->orderBy('status', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $allRoomAmenities = Amenity::where('type', 2)->get();

        $allRoomViews = \App\Models\RoomView::where('status', 1)->get();
        $allBedTypes = \App\Models\BedType::where('status', 1)->get();

        return response()->json([
            'message' => 'Lấy danh sách loại phòng thành công',
            'room_types' => $roomTypes,
            'all_room_amenities' => $allRoomAmenities,
            'all_room_views' => $allRoomViews,
            'all_bed_types' => $allBedTypes
        ], 200);
    }

    // Use Case 2: Thêm loại phòng
    public function store(Request $request)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        $request->validate([
            'name' => 'required|string|max:100',
            'room_size' => 'nullable|integer|min:1',
            'base_price' => 'required|numeric|min:0',
            'max_adults' => 'required|integer|min:1',
            'max_children' => 'required|integer|min:0',
            'view_id' => 'nullable|integer|exists:room_views,id',
            'bed_type_id' => 'nullable|integer|exists:bed_types,id',
            'status' => 'nullable|integer|in:0,1',
            'description' => 'nullable|string',
            'has_breakfast' => 'nullable|in:0,1',
            'smoking_policy' => 'nullable|in:0,1',
            'free_cancel_hours' => 'nullable|integer|min:0',
            'partial_refund_hours' => 'nullable|integer|min:0',
            'partial_refund_percent' => 'nullable|integer|min:0|max:100'
        ]);

        $roomType = RoomType::create([
            'hotel_id' => $hotelId,
            'name' => $request->name,
            'slug' => Str::slug($request->name) . '-' . time(),
            'room_size' => $request->room_size,
            'view_id' => $request->view_id,
            'bed_type_id' => $request->bed_type_id,
            'base_price' => $request->base_price,
            'max_adults' => $request->max_adults,
            'max_children' => $request->max_children,
            'status' => $request->status ?? 1,
            'description' => $request->description,
            'has_breakfast' => $request->has_breakfast ?? 0,
            'smoking_policy' => $request->smoking_policy ?? 0,
            'free_cancel_hours' => $request->free_cancel_hours,
            'partial_refund_hours' => $request->partial_refund_hours,
            'partial_refund_percent' => $request->partial_refund_percent,
        ]);

        $amenityIds = $request->input('amenity_ids', []);
        if (!empty($amenityIds)) {
            $roomType->amenities()->sync($amenityIds);
        }

        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $index => $file) {
                $path = $file->store('room_media', 'public');
                Media::create([
                    'model_type' => 'RoomType',
                    'model_id'   => $roomType->id,
                    'file_url'   => '/storage/' . $path,
                    'is_primary' => ($index === 0) ? 1 : 0,
                    'sort_order' => $index
                ]);
            }
        }

        return response()->json([
            'message' => 'Thêm loại phòng thành công',
            'room_type' => $roomType
        ], 201);
    }

    // Use Case 3: Cập nhật thông tin loại phòng
    public function update(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        $roomType = RoomType::where('id', $id)->where('hotel_id', $hotelId)->first();
        if (!$roomType) return response()->json(['message' => 'Không tìm thấy loại phòng'], 404);

        $request->validate([
            'name' => 'required|string|max:100',
            'room_size' => 'nullable|integer|min:1',
            'base_price' => 'required|numeric|min:0',
            'max_adults' => 'required|integer|min:1',
            'max_children' => 'required|integer|min:0',
            'status' => 'nullable|integer|in:0,1',
            'view_id' => 'nullable|integer',
            'bed_type_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'has_breakfast' => 'nullable|in:0,1',
            'smoking_policy' => 'nullable|in:0,1',
            'free_cancel_hours' => 'nullable|integer|min:0',
            'partial_refund_hours' => 'nullable|integer|min:0',
            'partial_refund_percent' => 'nullable|integer|min:0|max:100'
        ]);

        $roomType->update([
            'name' => $request->name,
            'room_size' => $request->room_size,
            'base_price' => $request->base_price,
            'max_adults' => $request->max_adults,
            'max_children' => $request->max_children,
            'status' => $request->status ?? 1,
            'view_id' => $request->view_id,
            'bed_type_id' => $request->bed_type_id,
            'description' => $request->description,
            'has_breakfast' => $request->has_breakfast ?? 0,
            'smoking_policy' => $request->smoking_policy ?? 0,
            'free_cancel_hours' => $request->free_cancel_hours,
            'partial_refund_hours' => $request->partial_refund_hours,
            'partial_refund_percent' => $request->partial_refund_percent,
        ]);

        $amenityIds = $request->input('amenity_ids', []);
        $roomType->amenities()->sync($amenityIds);

        if ($request->has('deleted_image_ids')) {
            $deletedIds = $request->input('deleted_image_ids');
            $medias = Media::whereIn('id', $deletedIds)
                ->where('model_type', 'RoomType')
                ->where('model_id', $roomType->id)
                ->get();
            foreach ($medias as $media) {
                $relativePath = str_replace('/storage/', '', $media->file_url);
                if (Storage::disk('public')->exists($relativePath)) {
                    Storage::disk('public')->delete($relativePath);
                }
                $media->delete();
            }
        }

        if ($request->hasFile('media')) {
            $hasPrimary = Media::where('model_type', 'RoomType')->where('model_id', $roomType->id)->where('is_primary', 1)->exists();
            foreach ($request->file('media') as $index => $file) {
                $path = $file->store('room_media', 'public');
                Media::create([
                    'model_type' => 'RoomType',
                    'model_id'   => $roomType->id,
                    'file_url'   => '/storage/' . $path,
                    'is_primary' => (!$hasPrimary && $index === 0) ? 1 : 0,
                    'sort_order' => $index
                ]);
            }
        }

        return response()->json([
            'message' => 'Cập nhật loại phòng thành công',
            'room_type' => $roomType
        ], 200);
    }

    // Use Case 4: Cập nhật tiện ích cho loại phòng 
    public function updateAmenities(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        $roomType = RoomType::where('id', $id)->where('hotel_id', $hotelId)->first();
        if (!$roomType) return response()->json(['message' => 'Không tìm thấy loại phòng'], 404);

        $request->validate([
            'amenity_ids' => 'required|array',
            'amenity_ids.*' => 'integer|exists:amenities,id'
        ]);

        $roomType->amenities()->sync($request->amenity_ids);

        return response()->json([
            'message' => 'Cập nhật tiện ích phòng thành công',
            'amenities' => $roomType->amenities
        ], 200);
    }

    // Use Case 5: Bật / Tắt trạng thái mở bán loại phòng (Toggle Status)
    public function destroy(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        $roomType = RoomType::where('id', $id)->where('hotel_id', $hotelId)->first();
        if (!$roomType) return response()->json(['message' => 'Không tìm thấy loại phòng'], 404);

        if ($roomType->status == 1) {
            // Đang mở bán -> Tạm ngưng bán
            Room::where('room_type_id', $roomType->id)
                ->where('hotel_id', $hotelId)
                ->where('status', 1)
                ->update(['status' => 3]); // Chuyển phòng trống sang bảo trì

            $roomType->status = 0;
            $roomType->save();

            return response()->json([
                'message' => 'Đã tạm ngưng mở bán loại phòng thành công!',
                'status' => 0
            ], 200);
        } else {
            // Đang tạm ngưng -> Mở bán lại
            Room::where('room_type_id', $roomType->id)
                ->where('hotel_id', $hotelId)
                ->where('status', 3)
                ->update(['status' => 1]); // Khôi phục sang sẵn sàng đón khách

            $roomType->status = 1;
            $roomType->save();

            return response()->json([
                'message' => 'Đã mở bán lại loại phòng thành công!',
                'status' => 1
            ], 200);
        }
    }

    // Use Case 6: Tải Ảnh / Video cho loại phòng
    public function uploadMedia(Request $request, int $id)
    {
        $hotelId = $this->getHotelId();
        if (!$hotelId) return response()->json(['message' => 'Chưa có thông tin khách sạn'], 400);

        $roomType = RoomType::where('id', $id)->where('hotel_id', $hotelId)->first();
        if (!$roomType) return response()->json(['message' => 'Không tìm thấy loại phòng'], 404);

        $request->validate([
            'media' => 'required|array',
            'media.*' => 'file|mimes:jpeg,png,jpg,webp,mp4,mov,avi|max:20480',
        ]);

        $uploadedPaths = [];

        if ($request->hasFile('media')) {
            Media::where('model_type', 'RoomType')->where('model_id', $roomType->id)->delete();

            foreach ($request->file('media') as $index => $file) {
                $path = $file->store('room_media', 'public');

                $media = Media::create([
                    'model_type' => 'RoomType',
                    'model_id'   => $roomType->id,
                    'file_url'   => '/storage/' . $path,
                    'is_primary' => ($index === 0) ? 1 : 0,
                    'sort_order' => $index
                ]);

                $uploadedPaths[] = asset('storage/' . $path);
            }
        }

        return response()->json([
            'message' => 'Tải media lên và lưu Database thành công!',
            'paths' => $uploadedPaths
        ], 200);
    }
    // Use Case 7: Xóa một ảnh cụ thể
    public function deleteMedia(int $mediaId)
    {
        try {
            // Tìm ảnh trong Database
            $media = Media::findOrFail($mediaId);

            // Xóa file vật lý trong thư mục storage để giải phóng ổ cứng máy chủ
            $relativePath = str_replace('/storage/', '', $media->file_url);
            if (Storage::disk('public')->exists($relativePath)) {
                Storage::disk('public')->delete($relativePath);
            }

            // Xóa dữ liệu lưu trong bảng
            $media->delete();

            return response()->json(['message' => 'Xóa ảnh thành công!'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Lỗi xóa ảnh: ' . $e->getMessage()], 500);
        }
    }

    // Use Case 8: Đặt một ảnh làm ảnh chính (Primary)
    public function setPrimaryMedia(int $mediaId)
    {
        try {
            // Tìm ảnh được chọn
            $media = Media::findOrFail($mediaId);

            // Bước 1: Hủy trạng thái ảnh chính của tất cả các ảnh khác cùng thuộc loại phòng này
            Media::where('model_type', $media->model_type)
                ->where('model_id', $media->model_id)
                ->update(['is_primary' => 0]);

            // Bước 2: Đặt ảnh vừa chọn làm ảnh chính
            $media->is_primary = 1;
            $media->save();

            return response()->json(['message' => 'Đã đặt làm ảnh bìa thành công!'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Lỗi cập nhật ảnh: ' . $e->getMessage()], 500);
        }
    }
}
