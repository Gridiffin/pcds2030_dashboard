<?php
/**
 * Test file for copy-paste sanitization functions
 * This file can be deleted after testing is complete
 */

require_once 'app/lib/functions.php';

// Test cases with various problematic copy-paste content
$test_cases = [
    'smart_quotes' => '"Smart quotes" and \'single quotes\' from Word',
    'unicode_spaces' => "Regular space\xc2\xa0non-breaking space\xe2\x80\x82en space",
    'em_dash' => 'Em dash — and en dash – from documents',
    'zero_width' => "Zero width\xe2\x80\x8bspace and\xe2\x80\x8czero width\xe2\x80\x8djoiner",
    'line_breaks' => "Line 1\r\nLine 2\rLine 3\nLine 4\n\n\nToo many breaks",
    'ellipsis' => 'Ellipsis… character',
    'mixed_content' => "\"This is a \"complex\" test with — various issues… including\r\nnon-breaking\xc2\xa0spaces and\xe2\x80\x8bzero-width characters.\"",
];

echo "<h1>Copy-Paste Sanitization Test Results</h1>\n";

foreach ($test_cases as $name => $content) {
    echo "<h3>Test Case: " . ucwords(str_replace('_', ' ', $name)) . "</h3>\n";
    echo "<p><strong>Original:</strong><br>" . htmlspecialchars($content) . "</p>\n";
    echo "<p><strong>Hex Dump:</strong><br>" . bin2hex($content) . "</p>\n";
    
    $sanitized = sanitize_copy_paste_content($content, true);
    echo "<p><strong>Sanitized:</strong><br>" . htmlspecialchars($sanitized) . "</p>\n";
    echo "<p><strong>Hex Dump:</strong><br>" . bin2hex($sanitized) . "</p>\n";
    echo "<hr>\n";
}

// Test program data sanitization
echo "<h2>Program Data Array Sanitization Test</h2>\n";

$test_program_data = [
    'program_name' => '"Smart Program" with — special chars',
    'brief_description' => "This is a program\r\nwith line breaks\nand\xc2\xa0spaces",
    'targets' => [
        [
            'target_text' => 'Target with "quotes" and — dashes',
            'status_description' => "Status with\xe2\x80\x8bzero-width chars"
        ]
    ]
];

echo "<p><strong>Original Program Data:</strong></p>\n";
echo "<pre>" . htmlspecialchars(print_r($test_program_data, true)) . "</pre>\n";

$sanitized_program_data = sanitize_program_data($test_program_data);

echo "<p><strong>Sanitized Program Data:</strong></p>\n";
echo "<pre>" . htmlspecialchars(print_r($sanitized_program_data, true)) . "</pre>\n";

echo "<p><em>Test completed. You can delete this file after verification.</em></p>\n";
?>
