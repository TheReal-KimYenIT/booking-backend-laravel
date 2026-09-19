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
} catch (\Exception $e) {}

try {
    DB::statement('ALTER TABLE contacts DROP FOREIGN KEY fk_contacts_booking_id');
} catch (\Exception $e) {}

try {
    Schema::table('contacts', function (Blueprint $table) {
        $table->dropColumn('booking_id');
    });
    echo "Dropped booking_id\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

$columns = Schema::getColumnListing('contacts');
echo "Columns now: " . implode(', ', $columns) . "\n";
