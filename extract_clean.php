<?php
$file = 'C:/Users/DELL/OneDrive/Desktop/CRM/HR_Recruitment_Module_PRD.pdf';
$raw = file_get_contents($file);

$output = '';

// Method 1: Extract BT...ET text with Tj/TJ operators
preg_match_all('/BT\s*(.*?)\s*ET/s', $raw, $bt_matches);
foreach ($bt_matches[1] as $block) {
    // Single string Tj
    preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/s', $block, $tj_matches);
    foreach ($tj_matches[1] as $t) {
        $t = stripcslashes($t);
        $t = preg_replace('/[^\x20-\x7E]/', ' ', $t);
        if (strlen(trim($t)) > 1) {
            $output .= $t . ' ';
        }
    }
    // Array TJ
    preg_match_all('/\[([^\[\]]*)\]\s*TJ/s', $block, $tj2_matches);
    foreach ($tj2_matches[1] as $arr) {
        preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/', $arr, $inner);
        foreach ($inner[1] as $t) {
            $t = stripcslashes($t);
            $t = preg_replace('/[^\x20-\x7E]/', ' ', $t);
            if (strlen(trim($t)) > 0) {
                $output .= $t;
            }
        }
        $output .= ' ';
    }
    $output .= "\n";
}

// Clean
$output = preg_replace('/\s{2,}/', ' ', $output);
$output = preg_replace('/[\n]{3,}/', "\n\n", $output);

file_put_contents('C:/Users/DELL/OneDrive/Desktop/CRM/prd_clean.txt', $output);
echo 'Chars: ' . strlen($output) . PHP_EOL;
echo 'Preview: ' . substr($output, 0, 1000) . PHP_EOL;
