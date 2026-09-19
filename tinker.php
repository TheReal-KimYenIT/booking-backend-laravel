<?php
\Illuminate\Support\Facades\Auth::shouldReceive('guard')->with('customer')->andReturnSelf();
\Illuminate\Support\Facades\Auth::shouldReceive('id')->andReturn(5);
$request = new \Illuminate\Http\Request();
$request->merge([
    'hotel_id'=>2, 
    'room_type_id'=>5, 
    'check_in'=>'2026-08-13', 
    'check_out'=>'2026-08-14', 
    'rooms_count'=>1, 
    'guest_name'=>'Test', 
    'guest_phone'=>'0123456789', 
    'guest_email'=>'test@test.com', 
    'services'=>[['id'=>1, 'quantity'=>1]]
]);
$controller = new \App\Http\Controllers\Api\Customer\BookingController();
$response = $controller->createBooking($request);
echo "RESPONSE_START\n";
echo json_encode($response);
echo "\nRESPONSE_END\n";
