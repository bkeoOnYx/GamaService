<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/content-store.php';

const GS_CONTACT_EMAIL = 'support.gamaservice@gmail.com';
const GS_CONTACT_RATE_LIMIT = 5;
const GS_CONTACT_RATE_WINDOW = 3600;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

function contact_respond(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['message' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function contact_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function contact_single_line(mixed $value, int $maximum): string
{
    $text = gs_text($value, $maximum);
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function contact_message_text(mixed $value, int $maximum): string
{
    $text = gs_text($value, $maximum);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '');
}

function contact_request_is_same_origin(): bool
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        return false;
    }

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return true;
    }

    $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
    return $scheme === 'https' && in_array($host, ['gamaservice.fr', 'www.gamaservice.fr'], true);
}

function contact_rate_allowed(): bool
{
    $path = gs_storage_dir() . DIRECTORY_SEPARATOR . 'contact-rate.json';
    $data = gs_read_json_file($path, ['visitors' => []]);
    $visitors = is_array($data['visitors'] ?? null) ? $data['visitors'] : [];
    $now = time();
    $minimum = $now - GS_CONTACT_RATE_WINDOW;

    foreach ($visitors as $key => $timestamps) {
        if (!is_array($timestamps)) {
            unset($visitors[$key]);
            continue;
        }

        $recent = array_values(array_filter(
            $timestamps,
            static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $minimum
        ));
        if ($recent === []) {
            unset($visitors[$key]);
        } else {
            $visitors[$key] = $recent;
        }
    }

    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $visitorKey = hash('sha256', $address);
    $attempts = is_array($visitors[$visitorKey] ?? null) ? $visitors[$visitorKey] : [];
    if (count($attempts) >= GS_CONTACT_RATE_LIMIT) {
        gs_write_json_file($path, ['visitors' => $visitors]);
        return false;
    }

    $attempts[] = $now;
    $visitors[$visitorKey] = $attempts;
    gs_write_json_file($path, ['visitors' => $visitors]);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    contact_respond(405, 'Méthode non autorisée.');
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 12000) {
    contact_respond(413, 'La demande est trop volumineuse.');
}

if (!contact_request_is_same_origin()) {
    contact_respond(403, 'La demande ne peut pas être envoyée depuis cette page.');
}

if (contact_single_line($_POST['website'] ?? '', 200) !== '') {
    contact_respond(200, 'Votre demande a bien été envoyée.');
}

$allowedServices = ['Minecraft', "Garry's Mod", 'Site web', 'Graphisme', 'Projet complet'];
$allowedBudgets = ['Moins de 500 €', '500 € à 1 500 €', '1 500 € à 5 000 €', 'Plus de 5 000 €', 'À définir'];
$allowedDeadlines = ['Dès que possible', 'Dans 1 à 2 mois', 'Dans 3 mois ou plus', 'À définir'];

$service = contact_single_line($_POST['service'] ?? '', 60);
$budget = contact_single_line($_POST['budget'] ?? '', 60);
$deadline = contact_single_line($_POST['deadline'] ?? '', 60);
$contactMethod = contact_single_line($_POST['contact-method'] ?? '', 160);
$project = contact_message_text($_POST['project'] ?? '', 3000);

if (!in_array($service, $allowedServices, true)
    || !in_array($budget, $allowedBudgets, true)
    || !in_array($deadline, $allowedDeadlines, true)
    || contact_length($project) < 20
) {
    contact_respond(422, 'Vérifiez les champs du formulaire avant de réessayer.');
}

try {
    $rateAllowed = contact_rate_allowed();
} catch (Throwable $exception) {
    error_log('GamaService contact form: rate limiter unavailable.');
    contact_respond(503, 'L’envoi est temporairement indisponible. Réessayez dans quelques minutes.');
}

if (!$rateAllowed) {
    header('Retry-After: ' . GS_CONTACT_RATE_WINDOW);
    contact_respond(429, 'Trop de demandes ont été envoyées. Réessayez dans une heure.');
}

$subject = 'Nouvelle demande GamaService - ' . $service;
if (function_exists('mb_encode_mimeheader')) {
    $subject = mb_encode_mimeheader($subject, 'UTF-8');
}

$message = "Une nouvelle demande a été envoyée depuis gamaservice.fr.\n\n"
    . "Service : {$service}\n"
    . "Budget : {$budget}\n"
    . "Échéance : {$deadline}\n"
    . 'Moyen de contact : ' . ($contactMethod !== '' ? $contactMethod : 'Non renseigné') . "\n\n"
    . "Projet :\n{$project}\n";

$headers = [
    'From: GamaService <no-reply@gamaservice.fr>',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . PHP_VERSION,
];

if (filter_var($contactMethod, FILTER_VALIDATE_EMAIL) !== false) {
    $headers[] = 'Reply-To: ' . $contactMethod;
} else {
    $headers[] = 'Reply-To: ' . GS_CONTACT_EMAIL;
}

if (!@mail(GS_CONTACT_EMAIL, $subject, $message, implode("\r\n", $headers))) {
    error_log('GamaService contact form: mail() failed.');
    contact_respond(503, 'L’envoi est temporairement indisponible. Réessayez dans quelques minutes.');
}

contact_respond(200, 'Votre demande a bien été envoyée. Nous vous répondrons rapidement.');
