<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rt = \App\Models\RoomType::with('roomInventories')->find(6);
$inv = $rt->roomInventories->first();
echo "\n====\n";
echo "Type: " . gettype($inv->apply_date) . "\n";
echo "Value: " . $inv->apply_date . "\n";
if ($inv->apply_date instanceof \Carbon\Carbon) {
    echo "Carbon format Y-m-d: " . $inv->apply_date->format('Y-m-d') . "\n";
}
echo "====\n";
