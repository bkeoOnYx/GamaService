<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/content-store.php';

const GS_ADMIN_EMAIL = 'support.gamaservice@gmail.com';
const GS_ADMIN_USERNAME = 'admin';
const GS_ADMIN_URL = 'https://gamaservice.fr/admin/';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, private');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '7200');
session_name('gamaservice_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function admin_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_csrf(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function admin_verify_csrf(): void
{
    $submitted = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals(admin_csrf(), $submitted)) {
        throw new RuntimeException('La session a expiré. Rechargez la page.');
    }
}

function admin_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function admin_redirect(string $anchor = ''): never
{
    header('Location: ' . GS_ADMIN_URL . $anchor, true, 303);
    exit;
}

function admin_credentials_path(): string
{
    return gs_storage_dir() . DIRECTORY_SEPARATOR . 'admin-credentials.json';
}

function admin_token_path(): string
{
    return gs_storage_dir() . DIRECTORY_SEPARATOR . 'admin-password-token.json';
}

function admin_rate_path(): string
{
    return gs_storage_dir() . DIRECTORY_SEPARATOR . 'admin-rate.json';
}

function admin_credentials(): array
{
    return gs_read_json_file(admin_credentials_path(), []);
}

function admin_password_configured(): bool
{
    $credentials = admin_credentials();
    return ($credentials['username'] ?? '') === GS_ADMIN_USERNAME
        && is_string($credentials['password_hash'] ?? null)
        && $credentials['password_hash'] !== '';
}

function admin_validate_password(string $password, string $confirmation): void
{
    $length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
    if ($length < 12 || $length > 200) {
        throw new RuntimeException('Le mot de passe doit contenir entre 12 et 200 caractères.');
    }
    if (!hash_equals($password, $confirmation)) {
        throw new RuntimeException('Les deux mots de passe ne correspondent pas.');
    }
}

function admin_save_password(string $password): void
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Le mot de passe n’a pas pu être sécurisé.');
    }

    gs_write_json_file(admin_credentials_path(), [
        'username' => GS_ADMIN_USERNAME,
        'password_hash' => $hash,
        'updated_at' => time(),
    ]);
}

function admin_rate_allowed(string $bucket, int $limit, int $window): bool
{
    $now = time();
    $ip = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $key = $bucket . ':' . $ip;
    $data = gs_read_json_file(admin_rate_path(), ['buckets' => []]);
    $buckets = is_array($data['buckets'] ?? null) ? $data['buckets'] : [];

    foreach ($buckets as $storedKey => $timestamps) {
        if (!is_array($timestamps)) {
            unset($buckets[$storedKey]);
            continue;
        }
        $buckets[$storedKey] = array_values(array_filter(
            $timestamps,
            static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $now - 3600
        ));
        if ($buckets[$storedKey] === []) {
            unset($buckets[$storedKey]);
        }
    }

    $recent = array_values(array_filter(
        $buckets[$key] ?? [],
        static fn (int $timestamp): bool => $timestamp > $now - $window
    ));
    if (count($recent) >= $limit) {
        return false;
    }

    $buckets[$key][] = $now;
    gs_write_json_file(admin_rate_path(), ['buckets' => $buckets]);
    return true;
}

function admin_clear_rate(string $bucket): void
{
    $ip = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $key = $bucket . ':' . $ip;
    $data = gs_read_json_file(admin_rate_path(), ['buckets' => []]);
    $buckets = is_array($data['buckets'] ?? null) ? $data['buckets'] : [];
    unset($buckets[$key]);
    gs_write_json_file(admin_rate_path(), ['buckets' => $buckets]);
}

function admin_authenticate(): void
{
    session_regenerate_id(true);
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function admin_login(string $username, string $password): bool
{
    if (!admin_rate_allowed('login', 6, 900)) {
        throw new RuntimeException('Trop de tentatives. Réessayez dans 15 minutes.');
    }

    $credentials = admin_credentials();
    $hash = (string) ($credentials['password_hash'] ?? '');
    $validUsername = hash_equals(GS_ADMIN_USERNAME, $username);
    if (!$validUsername || $hash === '' || !password_verify($password, $hash)) {
        return false;
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        admin_save_password($password);
    }
    admin_clear_rate('login');
    admin_authenticate();
    return true;
}

function admin_send_password_link(): void
{
    if (!admin_rate_allowed('password-email', 3, 3600)) {
        throw new RuntimeException('Trop de demandes. Réessayez dans une heure.');
    }

    $purpose = admin_password_configured() ? 'reset' : 'setup';
    $token = bin2hex(random_bytes(32));
    gs_write_json_file(admin_token_path(), [
        'token_hash' => hash('sha256', $token),
        'purpose' => $purpose,
        'expires_at' => time() + 900,
    ]);

    $link = GS_ADMIN_URL . '?token=' . $token;
    $verb = $purpose === 'setup' ? 'Initialisation' : 'Réinitialisation';
    $subject = $verb . ' du mot de passe GamaService';
    if (function_exists('mb_encode_mimeheader')) {
        $subject = mb_encode_mimeheader($subject, 'UTF-8');
    }
    $message = "Bonjour,\n\nUtilisez ce lien pour définir le mot de passe du compte admin GamaService :\n\n"
        . $link
        . "\n\nCe lien est valable 15 minutes et ne peut être utilisé qu’une fois. Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail.\n";
    $headers = [
        'From: GamaService <no-reply@gamaservice.fr>',
        'Reply-To: ' . GS_ADMIN_EMAIL,
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    if (!@mail(GS_ADMIN_EMAIL, $subject, $message, implode("\r\n", $headers))) {
        throw new RuntimeException('Le serveur n’a pas pu envoyer l’e-mail de sécurité.');
    }
}

function admin_valid_token(string $token): ?array
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return null;
    }

    $data = gs_read_json_file(admin_token_path(), []);
    $expected = (string) ($data['token_hash'] ?? '');
    if ($expected === '' || (int) ($data['expires_at'] ?? 0) < time()) {
        return null;
    }
    if (!hash_equals($expected, hash('sha256', $token))) {
        return null;
    }
    return $data;
}

function admin_consume_password_token(string $token, string $password, string $confirmation): void
{
    if (admin_valid_token($token) === null) {
        throw new RuntimeException('Ce lien est invalide ou a expiré.');
    }
    admin_validate_password($password, $confirmation);
    admin_save_password($password);
    gs_write_json_file(admin_token_path(), ['token_hash' => '', 'purpose' => '', 'expires_at' => 0]);
    admin_authenticate();
}

function admin_upload_image(string $field): ?string
{
    $upload = $_FILES[$field] ?? null;
    if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) $upload['error'] !== UPLOAD_ERR_OK || (int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('La capture doit peser au maximum 2 Mo.');
    }

    $temporary = (string) $upload['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Formats autorisés : JPG, PNG ou WebP.');
    }

    $dimensions = @getimagesize($temporary);
    if (!is_array($dimensions) || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 5000 || $dimensions[1] > 5000) {
        throw new RuntimeException('Les dimensions de la capture sont invalides ou trop grandes.');
    }

    $directory = gs_storage_dir() . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Impossible de préparer le dossier des captures.');
    }

    $sourceFunctions = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
    ];
    $sourceFunction = $sourceFunctions[$mime];
    if (function_exists($sourceFunction) && function_exists('imagewebp')) {
        $source = @$sourceFunction($temporary);
        if ($source !== false) {
            $ratio = min(1, 1600 / $dimensions[0], 1200 / $dimensions[1]);
            $width = max(1, (int) round($dimensions[0] * $ratio));
            $height = max(1, (int) round($dimensions[1] * $ratio));
            $canvas = imagecreatetruecolor($width, $height);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $dimensions[0], $dimensions[1]);
            $filename = bin2hex(random_bytes(16)) . '.webp';
            $destination = $directory . DIRECTORY_SEPARATOR . $filename;
            $saved = imagewebp($canvas, $destination, 82);
            imagedestroy($canvas);
            imagedestroy($source);
            if (!$saved) {
                throw new RuntimeException('La capture n’a pas pu être optimisée.');
            }
            chmod($destination, 0600);
            return 'media.php?file=' . $filename;
        }
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($temporary, $destination)) {
        throw new RuntimeException('Le téléversement de la capture a échoué.');
    }
    chmod($destination, 0600);
    return 'media.php?file=' . $filename;
}

function admin_find_index(array $items, string $id): ?int
{
    foreach ($items as $index => $item) {
        if (($item['id'] ?? '') === $id) {
            return $index;
        }
    }
    return null;
}

function admin_parse_features(string $value): array
{
    $lines = preg_split('/\R|,/', $value) ?: [];
    $features = array_values(array_filter(array_map(
        static fn (string $feature): string => gs_text($feature, 100),
        $lines
    )));
    return array_slice($features, 0, 6);
}

$pendingToken = (string) ($_GET['token'] ?? '');
$tokenData = $pendingToken !== '' ? admin_valid_token($pendingToken) : null;
$invalidToken = $pendingToken !== '' && $tokenData === null;

if (!empty($_SESSION['admin_authenticated'])) {
    $lastActivity = (int) ($_SESSION['admin_last_activity'] ?? 0);
    if ($lastActivity < time() - 7200) {
        unset($_SESSION['admin_authenticated'], $_SESSION['admin_last_activity']);
        session_regenerate_id(true);
        admin_flash('error', 'La session a expiré. Reconnectez-vous.');
    } else {
        $_SESSION['admin_last_activity'] = time();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        admin_verify_csrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'login' && empty($_SESSION['admin_authenticated'])) {
            if (!admin_password_configured()) {
                throw new RuntimeException('Le mot de passe administrateur doit d’abord être initialisé.');
            }
            if (!admin_login(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''))) {
                throw new RuntimeException('Identifiant ou mot de passe incorrect.');
            }
            admin_flash('success', 'Connexion réussie.');
            admin_redirect();
        }

        if ($action === 'request_password_link' && empty($_SESSION['admin_authenticated'])) {
            admin_send_password_link();
            admin_flash('success', 'L’e-mail de sécurité a été envoyé à l’adresse de support.');
            admin_redirect();
        }

        if ($action === 'set_password' && empty($_SESSION['admin_authenticated'])) {
            admin_consume_password_token(
                (string) ($_POST['token'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['password_confirmation'] ?? '')
            );
            admin_flash('success', 'Mot de passe enregistré. Vous êtes connecté.');
            admin_redirect();
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            admin_flash('success', 'Vous êtes déconnecté.');
            admin_redirect();
        }

        if (empty($_SESSION['admin_authenticated'])) {
            throw new RuntimeException('Vous devez être connecté.');
        }

        if ($action === 'change_password') {
            $credentials = admin_credentials();
            $hash = (string) ($credentials['password_hash'] ?? '');
            if ($hash === '' || !password_verify((string) ($_POST['current_password'] ?? ''), $hash)) {
                throw new RuntimeException('Le mot de passe actuel est incorrect.');
            }
            $password = (string) ($_POST['password'] ?? '');
            admin_validate_password($password, (string) ($_POST['password_confirmation'] ?? ''));
            admin_save_password($password);
            admin_flash('success', 'Mot de passe mis à jour.');
            admin_redirect('#compte');
        }

        $content = gs_read_content();

        if ($action === 'save_contact') {
            $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
            if (!is_string($email)) {
                throw new RuntimeException('L’adresse e-mail est invalide.');
            }
            $content['contact']['email'] = $email;
            gs_write_content($content);
            admin_flash('success', 'Adresse de contact mise à jour.');
            admin_redirect('#contact');
        }

        if ($action === 'save_plugin') {
            $id = gs_text($_POST['id'] ?? '', 80);
            $index = $id === '' ? null : admin_find_index($content['plugins'], $id);
            $uploadedImage = admin_upload_image('image_upload');
            $currentImage = gs_image_path($_POST['current_image'] ?? '');
            $image = $uploadedImage ?? $currentImage;
            $title = gs_text($_POST['title'] ?? '', 100);
            $summary = gs_text($_POST['summary'] ?? '', 700);
            $alt = gs_text($_POST['alt'] ?? '', 180);
            if ($title === '' || $summary === '') {
                throw new RuntimeException('Le nom et la présentation du plugin sont obligatoires.');
            }
            if ($image !== '' && $alt === '') {
                throw new RuntimeException('Décrivez la capture pour l’accessibilité et le référencement.');
            }
            $plugin = [
                'id' => $id !== '' ? $id : gs_new_id('plugin'),
                'label' => gs_text($_POST['label'] ?? 'Plugin réalisé', 60),
                'title' => $title,
                'summary' => $summary,
                'versions' => gs_text($_POST['versions'] ?? '', 80),
                'status' => gs_text($_POST['status'] ?? 'Privé', 40),
                'features' => admin_parse_features((string) ($_POST['features'] ?? '')),
                'image' => $image,
                'alt' => $alt,
                'published' => isset($_POST['published']),
            ];
            if ($index === null) {
                $content['plugins'][] = $plugin;
            } else {
                $content['plugins'][$index] = $plugin;
            }
            gs_write_content($content);
            admin_flash('success', 'Fiche plugin enregistrée.');
            admin_redirect('#plugins');
        }

        if ($action === 'delete_plugin') {
            $id = gs_text($_POST['id'] ?? '', 80);
            $content['plugins'] = array_values(array_filter(
                $content['plugins'],
                static fn (array $item): bool => ($item['id'] ?? '') !== $id
            ));
            gs_write_content($content);
            admin_flash('success', 'Fiche plugin supprimée.');
            admin_redirect('#plugins');
        }

        if ($action === 'save_review') {
            $id = gs_text($_POST['id'] ?? '', 80);
            $index = $id === '' ? null : admin_find_index($content['reviews'], $id);
            $name = gs_text($_POST['name'] ?? '', 80);
            $quote = gs_text($_POST['quote'] ?? '', 700);
            if ($name === '' || $quote === '') {
                throw new RuntimeException('Le nom et le texte de l’avis sont obligatoires.');
            }
            $review = [
                'id' => $id !== '' ? $id : gs_new_id('review'),
                'name' => $name,
                'project' => gs_text($_POST['project'] ?? '', 100),
                'quote' => $quote,
                'rating' => max(1, min(5, (int) ($_POST['rating'] ?? 5))),
                'published' => isset($_POST['published']),
            ];
            if ($index === null) {
                $content['reviews'][] = $review;
            } else {
                $content['reviews'][$index] = $review;
            }
            gs_write_content($content);
            admin_flash('success', 'Avis client enregistré.');
            admin_redirect('#avis');
        }

        if ($action === 'delete_review') {
            $id = gs_text($_POST['id'] ?? '', 80);
            $content['reviews'] = array_values(array_filter(
                $content['reviews'],
                static fn (array $item): bool => ($item['id'] ?? '') !== $id
            ));
            gs_write_content($content);
            admin_flash('success', 'Avis supprimé.');
            admin_redirect('#avis');
        }

        throw new RuntimeException('Action inconnue.');
    } catch (Throwable $exception) {
        admin_flash('error', $exception->getMessage());
        admin_redirect();
    }
}

$authenticated = !empty($_SESSION['admin_authenticated']);
$passwordConfigured = admin_password_configured();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$content = $authenticated ? gs_read_content() : null;
$blankPlugin = ['id' => '', 'label' => 'Plugin réalisé', 'title' => '', 'summary' => '', 'versions' => '', 'status' => 'Privé', 'features' => [], 'image' => '', 'alt' => '', 'published' => true];
$blankReview = ['id' => '', 'name' => '', 'project' => '', 'quote' => '', 'rating' => 5, 'published' => true];
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex,nofollow,noarchive" />
    <meta name="referrer" content="no-referrer" />
    <meta name="theme-color" content="#050713" />
    <link rel="icon" type="image/png" href="../assets/favicon.png" />
    <link rel="stylesheet" href="admin.css?v=20260810-2" />
    <title>Administration | GamaService</title>
  </head>
  <body>
    <header class="admin-header">
      <a class="admin-brand" href="../index.html"><img src="../assets/logo-gamaservice.webp" alt="" width="36" height="36" /><span>GamaService</span></a>
      <?php if ($authenticated): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="logout" /><button class="secondary-button" type="submit">Se déconnecter</button></form>
      <?php endif; ?>
    </header>
    <main class="admin-shell">
      <?php if (is_array($flash)): ?><p class="flash <?= admin_escape($flash['type'] ?? 'error') ?>" role="status"><?= admin_escape($flash['message'] ?? '') ?></p><?php endif; ?>

      <?php if (!$authenticated): ?>
        <section class="login-panel">
          <p class="admin-eyebrow">Espace privé</p>
          <h1>Administration GamaService</h1>
          <?php if ($invalidToken): ?><p class="flash error" role="alert">Ce lien est invalide ou a expiré.</p><?php endif; ?>
          <?php if ($tokenData !== null): ?>
            <p><?= ($tokenData['purpose'] ?? '') === 'setup' ? 'Créez le premier mot de passe' : 'Choisissez un nouveau mot de passe' ?> pour le compte <strong>admin</strong>.</p>
            <form class="login-form" method="post">
              <input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="set_password" /><input type="hidden" name="token" value="<?= admin_escape($pendingToken) ?>" />
              <label>Identifiant<input value="admin" disabled /></label>
              <label>Nouveau mot de passe<input type="password" name="password" required minlength="12" maxlength="200" autocomplete="new-password" /></label>
              <label>Confirmer le mot de passe<input type="password" name="password_confirmation" required minlength="12" maxlength="200" autocomplete="new-password" /></label>
              <button class="primary-button" type="submit">Enregistrer le mot de passe</button>
            </form>
          <?php elseif ($passwordConfigured): ?>
            <p>Connectez-vous avec le compte administrateur du site.</p>
            <form class="login-form" method="post">
              <input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="login" />
              <label>Identifiant<input name="username" value="admin" required maxlength="40" autocomplete="username" /></label>
              <label>Mot de passe<input type="password" name="password" required maxlength="200" autocomplete="current-password" /></label>
              <button class="primary-button" type="submit">Se connecter</button>
            </form>
            <form class="reset-form" method="post"><input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="request_password_link" /><button class="text-button" type="submit">Mot de passe oublié ?</button></form>
          <?php else: ?>
            <p>Le compte <strong>admin</strong> est prêt. Un lien sécurisé sera envoyé à <strong><?= admin_escape(GS_ADMIN_EMAIL) ?></strong> pour créer son premier mot de passe.</p>
            <form method="post"><input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="request_password_link" /><button class="primary-button" type="submit">Initialiser le mot de passe</button></form>
          <?php endif; ?>
        </section>
      <?php else: ?>
        <section class="dashboard-intro">
          <div><p class="admin-eyebrow">Contenu du site</p><h1>Tableau de bord</h1><p>Les modifications publiées sont visibles immédiatement sur le site.</p></div>
          <nav class="admin-tabs" aria-label="Sections"><a href="#plugins">Plugins</a><a href="#avis">Avis</a><a href="#contact">Contact</a><a href="#compte">Compte</a></nav>
        </section>

        <section id="plugins" class="admin-section">
          <div class="section-title"><div><p class="admin-eyebrow">Portfolio Minecraft</p><h2>Plugins réalisés</h2><p>Présentez les fonctionnalités sans publier de fichier, de code source ou de lien de téléchargement.</p></div><span><?= count($content['plugins']) ?> élément(s)</span></div>
          <div class="editor-list">
            <?php foreach (array_merge($content['plugins'], [$blankPlugin]) as $plugin): ?>
              <?php $isNew = ($plugin['id'] ?? '') === ''; $imagePath = gs_image_path($plugin['image'] ?? ''); ?>
              <article class="editor">
                <div class="editor-heading"><h3><?= $isNew ? 'Ajouter un plugin' : admin_escape($plugin['title']) ?></h3><?php if (!$isNew && $imagePath !== ''): ?><img src="../<?= admin_escape($imagePath) ?>" alt="" width="140" height="90" /><?php endif; ?></div>
                <form method="post" enctype="multipart/form-data">
                  <input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="save_plugin" /><input type="hidden" name="id" value="<?= admin_escape($plugin['id'] ?? '') ?>" /><input type="hidden" name="current_image" value="<?= admin_escape($plugin['image'] ?? '') ?>" />
                  <div class="field-grid"><label>Nom du plugin<input name="title" required maxlength="100" value="<?= admin_escape($plugin['title'] ?? '') ?>" /></label><label>Libellé<input name="label" maxlength="60" value="<?= admin_escape($plugin['label'] ?? '') ?>" /></label></div>
                  <label>Présentation<textarea name="summary" maxlength="700" required placeholder="Le besoin auquel répond le plugin et sa valeur pour le serveur."><?= admin_escape($plugin['summary'] ?? '') ?></textarea></label>
                  <div class="field-grid"><label>Versions compatibles<input name="versions" maxlength="80" placeholder="Paper 1.20.4 - 1.21.4" value="<?= admin_escape($plugin['versions'] ?? '') ?>" /></label><label>Statut<select name="status"><?php foreach (['Privé', 'En production', 'Projet livré', 'Maintenance'] as $status): ?><option value="<?= admin_escape($status) ?>" <?= ($plugin['status'] ?? 'Privé') === $status ? 'selected' : '' ?>><?= admin_escape($status) ?></option><?php endforeach; ?></select></label></div>
                  <label>Fonctionnalités, une par ligne<textarea name="features" maxlength="700" placeholder="Commandes personnalisées&#10;Interface de gestion&#10;Stockage sécurisé"><?= admin_escape(implode("\n", $plugin['features'] ?? [])) ?></textarea></label>
                  <div class="field-grid"><label>Capture facultative (JPG, PNG ou WebP, 2 Mo max.)<input type="file" name="image_upload" accept="image/jpeg,image/png,image/webp" /></label><label>Description de la capture<input name="alt" maxlength="180" value="<?= admin_escape($plugin['alt'] ?? '') ?>" /></label></div>
                  <label class="check"><input type="checkbox" name="published" <?= !empty($plugin['published']) ? 'checked' : '' ?> /> Publié sur le site</label>
                  <button class="primary-button" type="submit"><?= $isNew ? 'Ajouter le plugin' : 'Enregistrer' ?></button>
                </form>
                <?php if (!$isNew): ?><form class="delete-form" method="post"><input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="delete_plugin" /><input type="hidden" name="id" value="<?= admin_escape($plugin['id']) ?>" /><button class="danger-button" type="submit">Supprimer</button></form><?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section id="avis" class="admin-section">
          <div class="section-title"><div><p class="admin-eyebrow">Témoignages</p><h2>Avis clients</h2></div><span><?= count($content['reviews']) ?> élément(s)</span></div>
          <div class="editor-list">
            <?php foreach (array_merge($content['reviews'], [$blankReview]) as $review): ?>
              <?php $isNew = ($review['id'] ?? '') === ''; ?>
              <article class="editor">
                <h3><?= $isNew ? 'Ajouter un avis' : admin_escape($review['name']) ?></h3>
                <form method="post"><input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="save_review" /><input type="hidden" name="id" value="<?= admin_escape($review['id'] ?? '') ?>" />
                  <div class="field-grid"><label>Nom affiché<input name="name" required maxlength="80" value="<?= admin_escape($review['name'] ?? '') ?>" /></label><label>Type de projet<input name="project" required maxlength="100" value="<?= admin_escape($review['project'] ?? '') ?>" /></label></div>
                  <label>Avis<textarea name="quote" maxlength="700" required><?= admin_escape($review['quote'] ?? '') ?></textarea></label>
                  <div class="field-grid"><label>Note sur 5<select name="rating"><?php for ($rating = 5; $rating >= 1; $rating--): ?><option value="<?= $rating ?>" <?= (int) ($review['rating'] ?? 5) === $rating ? 'selected' : '' ?>><?= $rating ?>/5</option><?php endfor; ?></select></label><label class="check"><input type="checkbox" name="published" <?= !empty($review['published']) ? 'checked' : '' ?> /> Publié sur le site</label></div>
                  <button class="primary-button" type="submit"><?= $isNew ? 'Ajouter' : 'Enregistrer' ?></button>
                </form>
                <?php if (!$isNew): ?><form class="delete-form" method="post"><input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="delete_review" /><input type="hidden" name="id" value="<?= admin_escape($review['id']) ?>" /><button class="danger-button" type="submit">Supprimer</button></form><?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section id="contact" class="admin-section">
          <div class="section-title"><div><p class="admin-eyebrow">Coordonnées</p><h2>Adresse de contact</h2></div></div>
          <form class="editor compact-editor" method="post"><input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="save_contact" /><label>E-mail public<input type="email" name="email" required maxlength="160" value="<?= admin_escape($content['contact']['email'] ?? '') ?>" /></label><button class="primary-button" type="submit">Enregistrer</button></form>
        </section>

        <section id="compte" class="admin-section">
          <div class="section-title"><div><p class="admin-eyebrow">Sécurité</p><h2>Compte admin</h2><p>Le mot de passe n’est jamais stocké en clair.</p></div></div>
          <form class="editor compact-editor" method="post"><input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="change_password" /><label>Mot de passe actuel<input type="password" name="current_password" required maxlength="200" autocomplete="current-password" /></label><label>Nouveau mot de passe<input type="password" name="password" required minlength="12" maxlength="200" autocomplete="new-password" /></label><label>Confirmer le nouveau mot de passe<input type="password" name="password_confirmation" required minlength="12" maxlength="200" autocomplete="new-password" /></label><button class="primary-button" type="submit">Changer le mot de passe</button></form>
        </section>
      <?php endif; ?>
    </main>
  </body>
</html>
