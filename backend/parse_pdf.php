<?php
require 'vendor/autoload.php';

use Smalot\PdfParser\Parser;

$parser = new Parser();
$pdf = $parser->parseFile('C:/Users/DELL/OneDrive/Desktop/CRM/HR_Recruitment_Module_PRD.pdf');
$text = $pdf->getText();

file_put_contents('C:/Users/DELL/OneDrive/Desktop/CRM/prd_final.txt', $text);
echo "Done! Chars: " . strlen($text) . "\n";
echo "Preview:\n" . substr($text, 0, 3000) . "\n";
