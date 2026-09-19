<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$types = \App\Models\RoomType::where('hotel_id', 2)->get();
foreach ($types as $t) {
    echo "ID: {$t->id} - Name: {$t->name} - Price: {$t->base_price} - Status: {$t->status}\n";
}
