<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

try {
    DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    
    if (Schema::hasTable('users')) {
        Schema::drop('users');
        echo "Thanh cong xoa bang users\n";
    } else {
        echo "Bang users khong ton tai\n";
    }

    if (Schema::hasTable('password_reset_tokens')) {
        Schema::drop('password_reset_tokens');
        echo "Thanh cong xoa bang password_reset_tokens\n";
    }

    if (Schema::hasTable('sessions')) {
        Schema::drop('sessions');
        echo "Thanh cong xoa bang sessions\n";
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=1;');
} catch (\Exception $e) {
    echo "Loi: " . $e->getMessage() . "\n";
}
