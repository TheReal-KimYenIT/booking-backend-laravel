<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$review = DB::table('reviews')->leftJoin('customers', 'reviews.customer_id', '=', 'customers.id')->leftJoin('bookings', 'reviews.booking_id', '=', 'bookings.id')->select('reviews.*', DB::raw("CONCAT(customers.last_name, ' ', customers.first_name) as guest_name"), 'bookings.booking_code')->first();
echo json_encode($review);
