<?php

declare(strict_types=1);

function gs_root_dir(): string
{
    return dirname(__DIR__);
}

function gs_storage_dir(): string
{
    $configured = getenv('GAMASERVICE_DATA_DIR');
    $directory = is_string($configured) && $configured !== ''
        ? $configured
        : dirname(gs_root_dir()) . DIRECTORY_SEPARATOR . 'gamaservice-data';

    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Impossible de créer le dossier de données.');
    }

    return $directory;
}

function gs_default_content(): array
{
    $path = gs_root_dir() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'default-content.json';
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Le contenu initial est invalide.');
    }

    return $decoded;
}

function gs_read_json_file(string $path, array $fallback): array
{
    if (!is_file($path)) {
        return $fallback;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return $fallback;
    }

    try {
        flock($handle, LOCK_SH);
        $json = stream_get_contents($handle);
        $decoded = json_decode($json === false ? '' : $json, true);
        return is_array($decoded) ? $decoded : $fallback;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function gs_write_json_file(string $path, array $data): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Impossible de préparer le dossier de données.');
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $temporary = tempnam($directory, 'gs-');
    if ($temporary === false) {
        throw new RuntimeException('Impossible de créer le fichier temporaire.');
    }

    try {
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Impossible d’écrire les données.');
        }
        chmod($temporary, 0600);
        if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
            unlink($path);
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Impossible de publier les données.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function gs_content_path(): string
{
    return gs_storage_dir() . DIRECTORY_SEPARATOR . 'content.json';
}

function gs_read_content(): array
{
    $default = gs_default_content();
    $content = gs_read_json_file(gs_content_path(), $default);

    return [
        'contact' => is_array($content['contact'] ?? null) ? $content['contact'] : $default['contact'],
        'examples' => is_array($content['examples'] ?? null) ? array_values($content['examples']) : $default['examples'],
        'reviews' => is_array($content['reviews'] ?? null) ? array_values($content['reviews']) : [],
    ];
}

function gs_write_content(array $content): void
{
    gs_write_json_file(gs_content_path(), [
        'contact' => $content['contact'],
        'examples' => array_values($content['examples']),
        'reviews' => array_values($content['reviews']),
    ]);
}

function gs_public_content(array $content): array
{
    $examples = array_values(array_filter(
        $content['examples'],
        static fn (array $example): bool => ($example['published'] ?? false) === true
    ));
    $reviews = array_values(array_filter(
        $content['reviews'],
        static fn (array $review): bool => ($review['published'] ?? false) === true
    ));

    return [
        'contact' => ['email' => (string) ($content['contact']['email'] ?? 'support.gamaservice@gmail.com')],
        'examples' => $examples,
        'reviews' => $reviews,
    ];
}

function gs_text(mixed $value, int $maximum = 500): string
{
    $text = trim((string) $value);
    return function_exists('mb_substr')
        ? mb_substr($text, 0, $maximum)
        : substr($text, 0, $maximum);
}

function gs_image_path(mixed $value): string
{
    $path = gs_text($value, 220);
    if (preg_match('#^assets/[a-zA-Z0-9/_-]+\.(?:png|jpg|jpeg|webp)$#', $path) === 1) {
        return $path;
    }
    if (preg_match('#^media\.php\?file=[a-f0-9]{32}\.(?:jpg|png|webp)$#', $path) === 1) {
        return $path;
    }

    return 'assets/logo-gamaservice.webp';
}

function gs_new_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}
