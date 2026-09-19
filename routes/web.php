<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/storage/{path}', function ($path) {
    // 1. Tạo đường dẫn tuyệt đối trỏ vào thư mục nội bộ của hệ thống
    $fullPath = storage_path('app/public/' . $path);

    // 2. Kiểm tra xem file có thực sự nằm trên ổ cứng không
    if (!file_exists($fullPath)) {
        // Trả về JSON hiển thị rõ đường dẫn đang bị sai để dễ sửa lỗi
        return response()->json([
            'message' => 'LỖI: Không tìm thấy file ảnh!',
            'laravel_is_looking_at' => $fullPath
        ], 404);
    }

    // 3. Nếu tìm thấy, xuất file trực tiếp ra trình duyệt
    return response()->file($fullPath);
})->where('path', '.*'); //Ký tự '.*' giúp route nhận diện được dấu gạch chéo (/) của thư mục con