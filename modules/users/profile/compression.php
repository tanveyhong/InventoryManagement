<?php
/**
 * Performance Optimization Middleware
 * Adds output compression and minification
 */

// Start output buffering
ob_start('compressAndMinify');

function compressAndMinify($buffer) {
    // Enable gzip compression if not already enabled
    if (!ini_get('zlib.output_compression') && extension_loaded('zlib')) {
        ini_set('zlib.output_compression', 'On');
        ini_set('zlib.output_compression_level', '6');
    }
    
    // Minify HTML
    $search = [
        '/\>[^\S ]+/s',     // strip whitespaces after tags, except space
        '/[^\S ]+\</s',     // strip whitespaces before tags, except space
        '/(\s)+/s',         // shorten multiple whitespace sequences
        '/<!--(.|\s)*?-->/' // Remove HTML comments
    ];
    
    $replace = [
        '>',
        '<',
        '\\1',
        ''
    ];
    
    $buffer = preg_replace($search, $replace, $buffer);
    
    return $buffer;
}

// Set compression headers
header('Content-Encoding: gzip');
header('Vary: Accept-Encoding');
