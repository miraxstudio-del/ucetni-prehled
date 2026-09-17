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

/*
 * Soubory zacinajici teckou (.htaccess a podobne) se neservíruji. Do public
 * nepatri nic tajneho, ale server, ktery vydava konfiguracni soubory, jen
 * zbytecne prozrazuje, jak je aplikace postavena.
 */
$isHiddenFile = $requestedFile !== false
    && str_starts_with(basename($requestedFile), '.');

if (
    $publicDirectory !== false
    && $requestedFile !== false
    && ! $isHiddenFile
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

    /*
     * Staticke soubory jdou mimo Laravel, takze na ne bezpecnostni hlavicky
     * z middleware SecurityHeaders nedosahnou a je potreba je poslat tady.
     * nosniff je hlavni z nich: bez nej by prohlizec mohl u nerozpoznaneho
     * typu obsah uhadnout a spustit ho jako skript.
     */
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');

    header('Content-Type: '.$contentType);
    header('Content-Length: '.filesize($requestedFile));
    readfile($requestedFile);
    exit;
}

require $publicDirectory.'/index.php';
