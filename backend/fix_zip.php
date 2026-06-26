<?php
$iniPath = 'C:/xampp/php/php.ini';
$lines = file($iniPath);
$found = false;
foreach ($lines as $i => $line) {
    if (strpos($line, 'zip') !== false) {
        echo ($i + 1) . ': ' . $line;
        // If it's commented out, uncomment it
        if (preg_match('/^;.*extension=zip/', trim($line))) {
            $lines[$i] = "extension=zip\n";
            $found = true;
            echo "  --> FIXED\n";
        }
    }
}
if ($found) {
    file_put_contents($iniPath, implode('', $lines));
    echo "php.ini updated. Restart PHP processes.\n";
} else {
    echo "No commented zip line found. Already enabled or different format.\n";
}
