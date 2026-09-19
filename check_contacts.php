<?php
$cols = DB::select('SHOW COLUMNS FROM contacts');
foreach ($cols as $col) {
    echo $col->Field . "\n";
}
