<?php

declare(strict_types=1);

/*
 * Router pro vestaveny PHP server ve Windows vydani Účetní přehled.
 * Sestavene Vite soubory maji ve jmenu obsahovy hash, a proto je lze bezpecne
 * drzet v cache prohlizece rok. Pri prechodu v menu se znovu nacita jen HTML
 * a data konkretni stranky, ne stejne CSS, JavaScript a pisma.
 */

$publicDirectory = realpath(__DIR__.'/public');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestedFile = $publicDirectory === false
    ? false
    : realpath($publicDirectory.DIRECTORY_SEPARATOR.ltrim(urldecode($requestPath), '/'));

if (
    $publicDirectory !== false
    && $requestedFile !== false
    && str_starts_with($requestedFile, $publicDirectory.DIRECTORY_SEPARATOR)
    && is_file($requestedFile)
) {
    if (str_starts_with($requestPath, '/build/')) {
        header('Cache-Control: public, max-age=31536000, immutable');
    }

    $extension = strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION));
    $contentType = match ($extension) {
        'css' => 'text/css; charset=UTF-8',
        'js', 'mjs' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        default => mime_content_type($requestedFile) ?: 'application/octet-stream',
    };

    header('Content-Type: '.$contentType);
    header('Content-Length: '.filesize($requestedFile));
    readfile($requestedFile);
    exit;
}

require $publicDirectory.'/index.php';
