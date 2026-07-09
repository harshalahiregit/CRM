<?php
// Extract text from .docx files (ZIP-based format)
function readDocx($path) {
    if (!file_exists($path)) return "FILE NOT FOUND: $path";
    $zip = new ZipArchive();
    if ($zip->open($path) === true) {
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        // Remove XML tags, decode entities
        $text = strip_tags(str_replace(
            ['</w:p>', '</w:tr>', '<w:tab/>'],
            ["\n", "\n", "\t"],
            $xml
        ));
        return html_entity_decode(preg_replace('/\n{3,}/', "\n\n", $text), ENT_QUOTES, 'UTF-8');
    }
    return "COULD NOT OPEN: $path";
}

$files = [
    'AIR Talent Prediction Engine' => 'C:\\Users\\DELL\\Downloads\\AIR Talent Prediction Engine.docx',
    'Sangoe AIR OS Part 1'         => 'C:\\Users\\DELL\\Downloads\\Sangoe AIR OS Part 1.docx',
    'Sangoe AIR OS Part 2'         => 'C:\\Users\\DELL\\Downloads\\Sangoe AIR OS Part 2.docx',
    'HR_Recruitment_UpdatedFlow'   => 'C:\\Users\\DELL\\Downloads\\HR_Recruitment_UpdatedFlow.docx',
];

foreach ($files as $name => $path) {
    echo "\n\n" . str_repeat("=", 80) . "\n";
    echo "FILE: $name\n";
    echo str_repeat("=", 80) . "\n";
    echo readDocx($path);
    echo "\n";
}
