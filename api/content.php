<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/content-store.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Robots-Tag: noindex');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit;
}

try {
    echo json_encode(
        gs_public_content(gs_read_content()),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Contenu temporairement indisponible.']);
}

