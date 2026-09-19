<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

try {
    Schema::table('contacts', function (Blueprint $table) {
        $table->dropForeign('fk_contacts_booking_id');
    });
    echo "Dropped foreign key fk_contacts_booking_id\n";
} catch (\Exception $e) {
    echo "Error dropping FK: " . $e->getMessage() . "\n";
}

try {
    Schema::table('contacts', function (Blueprint $table) {
        if (Schema::hasColumn('contacts', 'booking_id')) {
            $table->dropColumn('booking_id');
            echo "Dropped column booking_id\n";
        }
    });
} catch (\Exception $e) {
    echo "Error dropping column: " . $e->getMessage() . "\n";
}

$columns = Schema::getColumnListing('contacts');
echo "Columns now: " . implode(', ', $columns) . "\n";
