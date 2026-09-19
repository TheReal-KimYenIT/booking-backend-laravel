<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$hotelId = 1; 

$request = \Illuminate\Http\Request::create('/api/partner/transactions', 'GET');
$controller = new \App\Http\Controllers\Api\Partner\TransactionController();

// Mock getHotelId
$reflection = new \ReflectionClass($controller);
$method = $reflection->getMethod('getHotelId');
// It's private, we can't easily. Let's just create an authenticated user.
// actually, just test the snippet again with the exact same data to see if peak_revenue is populated!
$query = \Illuminate\Support\Facades\DB::table('payments')
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->select('payments.*')
            ->where('bookings.hotel_id', $hotelId);
            
$allFilteredData = $query->get();

$dailyRevenues = $allFilteredData->where('payment_status', 1)->groupBy(function($item) {
    return \Carbon\Carbon::parse($item->created_at)->format('Y-m-d');
})->map(function($dayData) {
    return $dayData->sum('amount');
})->toArray();

$peakRevenue = !empty($dailyRevenues) ? max($dailyRevenues) : 0;
$peakDates = !empty($dailyRevenues) ? array_keys($dailyRevenues, $peakRevenue) : [];
$lowestRevenue = !empty($dailyRevenues) ? min($dailyRevenues) : 0;
$lowestDates = !empty($dailyRevenues) ? array_keys($dailyRevenues, $lowestRevenue) : [];

echo json_encode([
    'peak_date' => !empty($peakDates) ? implode(', ', array_map(function($d) { return \Carbon\Carbon::parse($d)->format('d/m'); }, $peakDates)) : null,
    'peak_revenue' => $peakRevenue,
    'lowest_date' => !empty($lowestDates) ? implode(', ', array_map(function($d) { return \Carbon\Carbon::parse($d)->format('d/m'); }, $lowestDates)) : null,
    'lowest_revenue' => $lowestRevenue
]);