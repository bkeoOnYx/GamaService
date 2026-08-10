<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/content-store.php';

$file = (string) ($_GET['file'] ?? '');
if (preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/', $file) !== 1) {
    http_response_code(404);
    exit;
}

$path = gs_storage_dir() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$extension = pathinfo($file, PATHINFO_EXTENSION);
$mimeTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($path);

