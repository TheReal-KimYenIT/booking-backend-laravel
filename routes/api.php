<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

// 1. Nhóm Public (Khách vãng lai)
use App\Http\Controllers\Api\PublicArea\AuthController as PublicAuthController;
use App\Http\Controllers\Api\PublicArea\HotelController as PublicHotelController;
use App\Http\Controllers\Api\PublicArea\RoomTypeController as PublicRoomTypeController;
use App\Http\Controllers\Api\PublicArea\ReviewController as PublicReviewController;
use App\Http\Controllers\Api\PublicArea\SystemContactController as PublicSystemContactController;

// 2. Nhóm Customer (Khách hàng)
use App\Http\Controllers\Api\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\Customer\BookingController as CustomerBookingController;
use App\Http\Controllers\Api\Customer\FavoriteController as CustomerFavoriteController;
use App\Http\Controllers\Api\Customer\ReviewController as CustomerReviewController;
use App\Http\Controllers\Api\Customer\PromotionController as CustomerPromotionController;
use App\Http\Controllers\Api\Customer\ChatController as CustomerChatController;
use App\Http\Controllers\Api\Customer\PaymentController as CustomerPaymentController;

// 3. Nhóm Partner (Đối tác khách sạn)
use App\Http\Controllers\Api\Partner\AuthController as PartnerAuthController;
use App\Http\Controllers\Api\Partner\ProfileController as PartnerProfileController;
use App\Http\Controllers\Api\Partner\StaffController as PartnerStaffController;
use App\Http\Controllers\Api\Partner\PartnerRoleController;
use App\Http\Controllers\Api\Partner\HotelController as PartnerHotelController;
use App\Http\Controllers\Api\Partner\RoomTypeController as PartnerRoomTypeController;
use App\Http\Controllers\Api\Partner\RoomController as PartnerRoomController;
use App\Http\Controllers\Api\Partner\ServiceController as PartnerServiceController;
use App\Http\Controllers\Api\Partner\PromotionController as PartnerPromotionController;
use App\Http\Controllers\Api\Partner\BookingController as PartnerBookingController;
use App\Http\Controllers\Api\Partner\SurchargeCategoryController as PartnerSurchargeCategoryController;
use App\Http\Controllers\Api\Partner\SupplyController as PartnerSupplyController;
use App\Http\Controllers\Api\Partner\ChatController as PartnerChatController;
use App\Http\Controllers\Api\Partner\RoomInventoryController as PartnerRoomInventoryController;
use App\Http\Controllers\Api\Partner\RoomMatrixController as PartnerRoomMatrixController;
use App\Http\Controllers\Api\Partner\TransactionController as PartnerTransactionController;

use App\Http\Controllers\Api\Partner\CustomerController as PartnerCustomerController;
use App\Http\Controllers\Api\Partner\ReviewController as PartnerReviewController;
use App\Http\Controllers\Api\Partner\RefundController as PartnerRefundController;

// 4. Nhóm Admin (Quản trị viên)
use App\Http\Controllers\Api\Admin\PartnerApprovalController as AdminPartnerApprovalController;
use App\Http\Controllers\Api\Admin\AmenityController as AdminAmenityController;
use App\Http\Controllers\Api\Admin\RoomViewController as AdminRoomViewController;
use App\Http\Controllers\Api\Admin\BedTypeController as AdminBedTypeController;
use App\Http\Controllers\Api\Admin\ContactController as AdminContactController;
use App\Http\Controllers\Api\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Api\Admin\SystemSettingController as AdminSystemSettingController;
use App\Http\Controllers\Api\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Api\Admin\AdminCustomerController as AdminCustomerController;
use App\Http\Controllers\Api\Admin\RefundController as AdminRefundController;


// ==========================================
//  0. HEALTH CHECK & DB STATUS
// ==========================================
Route::get('/health', function () {
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $tables = \Illuminate\Support\Facades\Schema::getTableListing();
        return response()->json([
            'status' => 'ok',
            'database' => 'connected',
            'tables_count' => count($tables),
            'tables' => array_slice($tables, 0, 10)
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'database' => 'failed',
            'error' => $e->getMessage()
        ], 500);
    }
});

// ==========================================
//  1. NHÓM API CÔNG KHAI (KHÔNG YÊU CẦU ĐĂNG NHẬP)
// ==========================================

// -- Xác thực (Auth) --
Route::middleware('throttle:5,1')->post('/login', [PublicAuthController::class, 'login']);
Route::post('/auth/register', [PublicAuthController::class, 'register']);
Route::post('/partner/register', [PublicAuthController::class, 'registerPartner']);

// -- Dữ liệu Master & Bộ lọc --
Route::get('/hotels/filters-data', [PublicHotelController::class, 'getFiltersData']);
Route::get('/rooms/master-data', [PublicHotelController::class, 'getRoomMasterData']);

// -- Tìm kiếm & Xem chi tiết --
Route::get('/hotels/destinations-count', [PublicHotelController::class, 'getDestinationsCount']);
Route::get('/hotels/search', [PublicHotelController::class, 'search']);
Route::get('/hotels/{id}', [PublicHotelController::class, 'getDetail']);
Route::get('/rooms/{id}', [PublicRoomTypeController::class, 'show']);
Route::get('/hotels/{hotel_id}/reviews', [PublicReviewController::class, 'index']);
Route::get('/hotels/{hotel_id}/services', [CustomerBookingController::class, 'getHotelServices']);

Route::post('/contacts', [PublicSystemContactController::class, 'store']);
Route::get('/promotions/active', [CustomerPromotionController::class, 'getActivePromotions']);

// -- Route lấy ảnh --
Route::get('/get-image', function (Request $request) {
    $relativePath = str_replace('/storage/', '', $request->query('path'));
    $fullPath = storage_path('app/public/' . $relativePath);
    if (!file_exists($fullPath)) {
        return response()->json(['message' => 'Không tìm thấy ảnh'], 404);
    }
    return response()->file($fullPath);
});

// -- API thanh toán và hệ thống (Public) --
Route::get('/payment/vnpay-ipn', [CustomerPaymentController::class, 'vnpayIpn']);
Route::get('/system-settings', [AdminSystemSettingController::class, 'index']);

// ==========================================
// THÊM MỚI: API ĐĂNG XUẤT CHUNG CHO MỌI ROLE
// ==========================================
Route::middleware('auth:sanctum')->post('/logout', [PublicAuthController::class, 'logout']);


// ==========================================
//  2. NHÓM API KHÁCH HÀNG (YÊU CẦU ĐĂNG NHẬP CUSTOMER)
// ==========================================
Route::middleware('auth:sanctum')->prefix('customer')->group(function () {

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // -- Quản lý Hồ sơ (Profile) --
    Route::get('/profile', [CustomerAuthController::class, 'getProfile']);
    Route::put('/profile', [CustomerAuthController::class, 'updateProfile']);
    Route::post('/change-password', [CustomerAuthController::class, 'changePassword']);
    Route::delete('/account', [CustomerAuthController::class, 'deleteAccount']);

    // -- Quản lý Đặt phòng (Bookings) --
    Route::post('/bookings', [CustomerBookingController::class, 'createBooking']);
    Route::get('/my-bookings', [CustomerBookingController::class, 'myBookings']);
    Route::post('/bookings/{id}/cancel', [CustomerBookingController::class, 'cancelMyBooking']);

    // -- Quản lý Yêu thích & Đánh giá --
    Route::get('/favorites', [CustomerFavoriteController::class, 'getFavorites']);
    Route::post('/favorites/{hotelId}', [CustomerFavoriteController::class, 'toggleFavorite']);
    Route::post('/reviews', [CustomerReviewController::class, 'store']);

    // -- Chat & Khuyến mãi --
    Route::get('/bookings/{booking}/chat', [CustomerChatController::class, 'index']);
    Route::get('/hotels/{hotelId}/chat', [CustomerChatController::class, 'getPreBookingChat']);
    Route::get('/chats', [CustomerChatController::class, 'getAllThreads']);
    Route::post('/chat/{thread}/messages', [CustomerChatController::class, 'store']);
    Route::get('/chat/{threadId}/messages', [PartnerChatController::class, 'getMessages']);

    Route::post('/promotions/check', [CustomerPromotionController::class, 'checkPromotion']);
    Route::post('/payment/vnpay', [CustomerPaymentController::class, 'createVnpayUrl']);
});


// ==========================================
// 3. NHÓM API ĐỐI TÁC KHÁCH SẠN (YÊU CẦU ĐĂNG NHẬP PARTNER)
// ==========================================
Route::middleware('auth:sanctum')->prefix('partner')->group(
    function () {

        // -- Quản lý Hồ sơ Chủ khách sạn --
        Route::get('/profile', [PartnerProfileController::class, 'getProfile']);
        Route::put('/profile', [PartnerProfileController::class, 'updateProfile']);
        Route::put('/profile/change-password', [PartnerProfileController::class, 'changePassword']);

        // -- Quản lý Thông tin Khách sạn --
        Route::get('/hotel', [PartnerHotelController::class, 'show']);
        Route::put('/hotel', [PartnerHotelController::class, 'update']);
        Route::post('/hotel/images', [PartnerHotelController::class, 'uploadImage']);
        Route::post('/hotel/reorder-images', [PartnerHotelController::class, 'reorderImages']);
        Route::get('/dashboard-stats', [PartnerHotelController::class, 'getStats']);
        Route::get('/hotel/amenities', [PartnerHotelController::class, 'getHotelAmenities']);
        Route::post('/hotel/amenities', [PartnerHotelController::class, 'updateAmenities']);

        // -- Quản lý Loại Phòng (Hạng phòng) --
        Route::apiResource('room-types', PartnerRoomTypeController::class)->except(['show']);
        Route::post('/room-types/{id}/amenities', [PartnerRoomTypeController::class, 'updateAmenities']);
        Route::post('/room-types/{id}/media', [PartnerRoomTypeController::class, 'uploadMedia']);
        Route::delete('/media/{mediaId}', [PartnerRoomTypeController::class, 'deleteMedia']);
        Route::post('/media/{mediaId}/set-primary', [PartnerRoomTypeController::class, 'setPrimaryMedia']);

        // -- Quản lý Sơ đồ Phòng vật lý --
        Route::get('/room-amenities', [PartnerRoomController::class, 'getRoomAmenities']);
        Route::get('/rooms', [PartnerRoomController::class, 'getRooms']);
        Route::post('/rooms', [PartnerRoomController::class, 'storeRoom']);
        Route::put('/rooms/{id}', [PartnerRoomController::class, 'updateRoom']);
        Route::delete('/rooms/{id}', [PartnerRoomController::class, 'deleteRoom']);
        Route::get('/rooms/available/{roomTypeId}', [PartnerRoomController::class, 'getAvailableRoomsByType']);

        // -- Quản lý Dịch vụ --
        Route::get('/services', [PartnerServiceController::class, 'getServices']);
        Route::post('/services', [PartnerServiceController::class, 'storeService']);
        Route::put('/services/{id}', [PartnerServiceController::class, 'updateService']);
        Route::delete('/services/{id}', [PartnerServiceController::class, 'deleteService']);
        Route::get('/surcharge-categories', [PartnerServiceController::class, 'getSurchargeCategories']);
        Route::post('/bookings/{id}/surcharges', [PartnerServiceController::class, 'addBookingSurcharge']);

        // -- Quản lý Minibar --
        Route::get('/minibars', [PartnerServiceController::class, 'getMinibars']);
        Route::post('/minibars', [PartnerServiceController::class, 'storeMinibar']);
        Route::put('/minibars/{id}', [PartnerServiceController::class, 'updateMinibar']);
        Route::delete('/minibars/{id}', [PartnerServiceController::class, 'deleteMinibar']);

        // -- Quản lý Đồ dùng tiêu hao --
        Route::get('/supplies', [PartnerSupplyController::class, 'getSupplies']);
        Route::post('/supplies', [PartnerSupplyController::class, 'storeSupply']);
        Route::put('/supplies/{id}', [PartnerSupplyController::class, 'updateSupply']);
        Route::delete('/supplies/{id}', [PartnerSupplyController::class, 'deleteSupply']);

        // -- Quản lý Khuyến mãi --
        Route::patch('/promotions/{id}/end-early', [PartnerPromotionController::class, 'endEarly']);
        Route::get('/promotions/{id}/stats', [PartnerPromotionController::class, 'stats']);
        Route::apiResource('promotions', PartnerPromotionController::class)->only(['index', 'store', 'update', 'destroy']);

        // -- Quản lý Đặt phòng (Check-in/Check-out/Menu) --
        Route::get('/bookings', [PartnerBookingController::class, 'index']);
        Route::get('/bookings/{id}', [PartnerBookingController::class, 'show']);
        Route::get('/bookings/{id}/payment', [PartnerBookingController::class, 'getPaymentInfo']);
        Route::get('/bookings/{id}/available-rooms', [PartnerBookingController::class, 'getAvailableRooms']);
        Route::put('/bookings/{id}/guests', [PartnerBookingController::class, 'updateGuests']);
        Route::put('/bookings/{id}/change-room', [PartnerBookingController::class, 'changeRoom']);

        Route::get('/bookings/{id}/menu', [PartnerBookingController::class, 'getMenuAndCart']);
        Route::post('/bookings/{id}/add-service', [PartnerBookingController::class, 'addExtraService']);
        Route::post('/bookings/{id}/add-minibar', [PartnerBookingController::class, 'addExtraMinibar']);
        Route::delete('/bookings/{id}/remove-service/{cartId}', [PartnerBookingController::class, 'removeExtraService']);
        Route::put('/bookings/{id}/update-service/{cartId}', [PartnerBookingController::class, 'updateExtraService']);
        Route::put('/bookings/{id}/notes', [PartnerBookingController::class, 'updateBookingNotes']);

        Route::put('/bookings/{id}/confirm', [PartnerBookingController::class, 'confirmBooking']);
        Route::put('/bookings/{id}/check-out', [PartnerBookingController::class, 'checkOutAndPay']);
        Route::put('/bookings/{id}/cancel', [PartnerBookingController::class, 'cancelBooking']);
        Route::put('/bookings/{id}/estimated-time', [PartnerBookingController::class, 'updateEstimatedTime']);
        Route::put('/bookings/{id}/no-show', [PartnerBookingController::class, 'markAsNoShow']);
        Route::post('/bookings/{id}/check-in', [PartnerBookingController::class, 'checkIn']);

        // -- Quản lý Phụ thu & Đền bù --
        Route::apiResource('surcharge-categories', PartnerSurchargeCategoryController::class);
        Route::post('/bookings/{id}/add-surcharge', [PartnerBookingController::class, 'addSurcharge']);
        Route::delete('/bookings/{id}/remove-surcharge/{surchargeId}', [PartnerBookingController::class, 'removeSurcharge']);
        Route::post('/bookings/{id}/add-damaged-item', [PartnerBookingController::class, 'addDamagedItem']);
        Route::delete('/bookings/{id}/remove-damaged-item/{itemId}', [PartnerBookingController::class, 'removeDamagedItem']);

        Route::get('/bookings/{id}/export-invoice', [PartnerBookingController::class, 'exportInvoice']);

        // -- Chat & Hội thoại --
        Route::get('/chat/threads', [PartnerChatController::class, 'index']);
        Route::post('/chat/{thread}/messages', [PartnerChatController::class, 'store']);
        Route::put('/chat/threads/{id}/status', [PartnerChatController::class, 'updateStatus']);

        // -- Quản lý Nhân viên --
        Route::patch('staffs/{id}/toggle-status', [PartnerStaffController::class, 'toggleStatus']);
        Route::apiResource('staffs', PartnerStaffController::class)->except(['show']);
        Route::apiResource('roles', PartnerRoleController::class);

        // -- Quản lý Kho phòng & Giao dịch --
        Route::get('/room-inventory', [PartnerRoomInventoryController::class, 'index']);
        Route::post('/room-inventory/bulk-update', [PartnerRoomInventoryController::class, 'updateBulk']);
        Route::get('/room-matrix-grid', [PartnerRoomMatrixController::class, 'getMatrix']);

        Route::get('/transactions/export', [PartnerTransactionController::class, 'export']);
        Route::get('/transactions', [PartnerTransactionController::class, 'index']);




        Route::get('/customers', [PartnerCustomerController::class, 'index']);
        Route::post('/customers/{id}/toggle-block', [PartnerCustomerController::class, 'toggleBlock']);
        Route::get('/customers/{id}/bookings', [PartnerCustomerController::class, 'getCustomerBookings']);

        Route::get('/reviews', [PartnerReviewController::class, 'index']);
        Route::put('/reviews/{id}/reply', [PartnerReviewController::class, 'reply']);


        Route::get('/refunds', [PartnerRefundController::class, 'index']);
        Route::put('/refunds/{id}/confirm', [PartnerRefundController::class, 'confirmRefund']);
    }
);


// ==========================================
//  4. NHÓM API ADMIN (YÊU CẦU ĐĂNG NHẬP ADMIN)
// ==========================================
Route::get('/dashboard-test-noauth', [\App\Http\Controllers\Api\Admin\AdminDashboardController::class, 'getStats']);

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/dashboard-test-auth', function() { return response()->json(['status' => 'ok']); });
    Route::get('/dashboard-stats', [\App\Http\Controllers\Api\Admin\AdminDashboardController::class, 'getStats']);

    // -- Quản lý xét duyệt Đối tác/Khách sạn --
    Route::get('/pending-partners', [AdminPartnerApprovalController::class, 'getPendingPartners']);
    Route::post('/approve-partner/{hotelId}', [AdminPartnerApprovalController::class, 'approvePartner']);
    Route::post('/reject-partner/{hotelId}', [AdminPartnerApprovalController::class, 'rejectPartner']);
    Route::get('/approved-partners', [AdminPartnerApprovalController::class, 'getApprovedPartners']);
    Route::post('/suspend-partner/{id}', [AdminPartnerApprovalController::class, 'suspendPartner']);
    Route::put('/hotels/{hotelId}/commission', [AdminPartnerApprovalController::class, 'updateCommission']);

    // -- Quản lý Master Data --
    Route::apiResource('amenities', AdminAmenityController::class);
    Route::apiResource('room-views', AdminRoomViewController::class);
    Route::apiResource('bed-types', AdminBedTypeController::class);

    // -- Xử lý liên hệ & Khuyến mãi --
    Route::get('/contacts', [AdminContactController::class, 'index']);
    Route::put('/contacts/{id}/resolve', [AdminContactController::class, 'resolve']);
    Route::put('/contacts/{id}/status', [AdminContactController::class, 'updateStatus']);
    Route::delete('/contacts/{id}', [AdminContactController::class, 'destroy']);

    Route::get('/promotions', [AdminPromotionController::class, 'index']);
    Route::post('/promotions', [AdminPromotionController::class, 'store']);
    Route::put('/promotions/{id}', [AdminPromotionController::class, 'update']);
    Route::delete('/promotions/{id}', [AdminPromotionController::class, 'destroy']);

    // -- Giao dịch & Cài đặt hệ thống --
    Route::get('transactions/export', [AdminTransactionController::class, 'exportCsv']);
    Route::get('transactions', [AdminTransactionController::class, 'index']);


    Route::get('system-settings', [AdminSystemSettingController::class, 'index']);
    Route::post('system-settings', [AdminSystemSettingController::class, 'update']);

    // -- Quản lý Khách hàng --
    Route::get('customers', [AdminCustomerController::class, 'index']);
    Route::post('customers/{id}/toggle-status', [AdminCustomerController::class, 'toggleStatus']);

    // -- Quản lý Hoàn tiền hệ thống --
    Route::get('/refunds', [AdminRefundController::class, 'index']);
    Route::put('/refunds/{id}/confirm', [AdminRefundController::class, 'confirmRefund']);
});
