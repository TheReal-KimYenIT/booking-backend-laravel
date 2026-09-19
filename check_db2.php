<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = ['booking_surcharges', 'booking_damaged_items'];
foreach($tables as $table) {
    echo strtoupper($table) . ":\n";
    $columns = DB::select('SHOW COLUMNS FROM ' . $table);
    foreach($columns as $col) {
        if(strpos($col->Type, 'decimal') !== false || strpos($col->Type, 'int') !== false) {
            echo $col->Field . ' -> ' . $col->Type . "\n";
        }
    }
    echo "------------------\n";
}
