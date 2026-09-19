<?php
$req = new \Illuminate\Http\Request(['booking_id' => 88]);
try {
    $res = app(\App\Http\Controllers\Api\Customer\PaymentController::class)->createVnpayUrl($req);
    print_r($res);
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString();
} catch (\Error $e) {
    echo "Error: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
