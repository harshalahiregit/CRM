<?php
$file = 'C:/Users/DELL/OneDrive/Desktop/CRM/HR_Recruitment_Module_PRD.pdf';
$content = file_get_contents($file);

// Extract text streams from PDF
preg_match_all('/stream(.*?)endstream/s', $content, $matches);
$text = '';
foreach ($matches[1] as $stream) {
    // Try to decompress if gzipped
    $decompressed = @gzuncompress($stream);
    if ($decompressed !== false) {
        $text .= $decompressed . "\n";
    }
    // Also grab raw readable text
    $readable = preg_replace('/[^\x20-\x7E\n\r\t]/', ' ', $stream);
    $readable = preg_replace('/\s{4,}/', ' ', $readable);
    if (strlen(trim($readable)) > 10) {
        $text .= $readable . "\n";
    }
}

// Also grab BT...ET text blocks
preg_match_all('/BT(.*?)ET/s', $content, $bt);
$btText = '';
foreach ($bt[1] as $block) {
    // Extract Tj and TJ text
    preg_match_all('/\(([^)]+)\)\s*Tj/', $block, $tj);
    foreach ($tj[1] as $t) {
        $btText .= $t . ' ';
    }
    preg_match_all('/\[([^\]]+)\]\s*TJ/', $block, $tj2);
    foreach ($tj2[1] as $t) {
        preg_match_all('/\(([^)]+)\)/', $t, $inner);
        foreach ($inner[1] as $i) {
            $btText .= $i . ' ';
        }
    }
}

$output = "=== BT/ET Text ===\n" . $btText . "\n\n=== Stream Text ===\n" . $text;
file_put_contents('C:/Users/DELL/OneDrive/Desktop/CRM/prd_extracted.txt', $output);
echo "Done! Chars: " . strlen($output);
