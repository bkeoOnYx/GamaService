<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/content-store.php';

const GS_ADMIN_EMAIL = 'support.gamaservice@gmail.com';
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

function admin_auth_path(): string
{
    return gs_storage_dir() . DIRECTORY_SEPARATOR . 'admin-auth.json';
}

function admin_rate_path(): string
{
    return gs_storage_dir() . DIRECTORY_SEPARATOR . 'admin-rate.json';
}

function admin_rate_allowed(): bool
{
    $now = time();
    $ipKey = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $data = gs_read_json_file(admin_rate_path(), ['requests' => []]);
    $requests = is_array($data['requests'] ?? null) ? $data['requests'] : [];

    foreach ($requests as $key => $timestamps) {
        if (!is_array($timestamps)) {
            unset($requests[$key]);
            continue;
        }
        $requests[$key] = array_values(array_filter(
            $timestamps,
            static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $now - 3600
        ));
        if ($requests[$key] === []) {
            unset($requests[$key]);
        }
    }

    $recentForIp = array_filter(
        $requests[$ipKey] ?? [],
        static fn (int $timestamp): bool => $timestamp > $now - 900
    );
    $globalCount = array_sum(array_map('count', $requests));
    if (count($recentForIp) >= 3 || $globalCount >= 12) {
        return false;
    }

    $requests[$ipKey][] = $now;
    gs_write_json_file(admin_rate_path(), ['requests' => $requests]);
    return true;
}

function admin_send_login_link(): void
{
    if (!admin_rate_allowed()) {
        throw new RuntimeException('Trop de demandes. Réessayez dans quelques minutes.');
    }

    $token = bin2hex(random_bytes(32));
    gs_write_json_file(admin_auth_path(), [
        'token_hash' => hash('sha256', $token),
        'expires_at' => time() + 900,
    ]);

    $link = GS_ADMIN_URL . '?token=' . $token;
    $subject = 'Connexion à l’administration GamaService';
    if (function_exists('mb_encode_mimeheader')) {
        $subject = mb_encode_mimeheader($subject, 'UTF-8');
    }
    $message = "Bonjour,\n\nVoici votre lien de connexion à l’administration GamaService :\n\n"
        . $link
        . "\n\nCe lien est valable 15 minutes et ne peut être utilisé qu’une fois.\n";
    $headers = [
        'From: GamaService <no-reply@gamaservice.fr>',
        'Reply-To: ' . GS_ADMIN_EMAIL,
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    if (!@mail(GS_ADMIN_EMAIL, $subject, $message, implode("\r\n", $headers))) {
        throw new RuntimeException('Le serveur n’a pas pu envoyer le lien. Vérifiez la fonction e-mail OVH.');
    }
}

function admin_consume_token(string $token): bool
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return false;
    }

    $auth = gs_read_json_file(admin_auth_path(), []);
    $expected = (string) ($auth['token_hash'] ?? '');
    $expiresAt = (int) ($auth['expires_at'] ?? 0);
    if ($expected === '' || $expiresAt < time() || !hash_equals($expected, hash('sha256', $token))) {
        return false;
    }

    gs_write_json_file(admin_auth_path(), ['token_hash' => '', 'expires_at' => 0]);
    session_regenerate_id(true);
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return true;
}

function admin_upload_image(string $field): ?string
{
    $upload = $_FILES[$field] ?? null;
    if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) $upload['error'] !== UPLOAD_ERR_OK || (int) $upload['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('L’image doit peser au maximum 5 Mo.');
    }

    $temporary = (string) $upload['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Formats autorisés : JPG, PNG ou WebP.');
    }

    $dimensions = @getimagesize($temporary);
    if (!is_array($dimensions) || $dimensions[0] > 6000 || $dimensions[1] > 6000) {
        throw new RuntimeException('Les dimensions de l’image sont invalides ou trop grandes.');
    }

    $uploadsDirectory = gs_storage_dir() . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadsDirectory) && !mkdir($uploadsDirectory, 0700, true) && !is_dir($uploadsDirectory)) {
        throw new RuntimeException('Impossible de préparer le dossier d’images.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $destination = $uploadsDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($temporary, $destination)) {
        throw new RuntimeException('Le téléversement de l’image a échoué.');
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

$pendingToken = (string) ($_GET['token'] ?? '');
if ($pendingToken !== '' && preg_match('/^[a-f0-9]{64}$/', $pendingToken) !== 1) {
    $pendingToken = '';
    admin_flash('error', 'Ce lien de connexion est invalide.');
}
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

        if ($action === 'consume_token' && empty($_SESSION['admin_authenticated'])) {
            if (!admin_consume_token((string) ($_POST['token'] ?? ''))) {
                throw new RuntimeException('Ce lien est invalide ou a expiré.');
            }
            admin_flash('success', 'Connexion réussie.');
            admin_redirect();
        }

        if ($action === 'request_link' && empty($_SESSION['admin_authenticated'])) {
            admin_send_login_link();
            admin_flash('success', 'Le lien de connexion a été envoyé à l’adresse de support.');
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

        if ($action === 'save_example') {
            $id = gs_text($_POST['id'] ?? '', 80);
            $index = $id === '' ? null : admin_find_index($content['examples'], $id);
            $uploadedImage = admin_upload_image('image_upload');
            $title = gs_text($_POST['title'] ?? '', 100);
            $alt = gs_text($_POST['alt'] ?? '', 180);
            if ($title === '' || $alt === '') {
                throw new RuntimeException('Le titre et le texte alternatif de l’image sont obligatoires.');
            }
            $tags = array_values(array_filter(array_map(
                static fn (string $tag): string => gs_text($tag, 30),
                explode(',', (string) ($_POST['tags'] ?? ''))
            )));
            $example = [
                'id' => $id !== '' ? $id : gs_new_id('example'),
                'kicker' => gs_text($_POST['kicker'] ?? 'Concept de démonstration', 60),
                'title' => $title,
                'description' => gs_text($_POST['description'] ?? '', 500),
                'tags' => array_slice($tags, 0, 6),
                'image' => $uploadedImage ?? gs_image_path($_POST['current_image'] ?? ''),
                'alt' => $alt,
                'published' => isset($_POST['published']),
            ];
            if ($index === null) {
                $content['examples'][] = $example;
            } else {
                $content['examples'][$index] = $example;
            }
            gs_write_content($content);
            admin_flash('success', 'Exemple Minecraft enregistré.');
            admin_redirect('#minecraft');
        }

        if ($action === 'delete_example') {
            $id = gs_text($_POST['id'] ?? '', 80);
            $content['examples'] = array_values(array_filter(
                $content['examples'],
                static fn (array $item): bool => ($item['id'] ?? '') !== $id
            ));
            gs_write_content($content);
            admin_flash('success', 'Exemple supprimé.');
            admin_redirect('#minecraft');
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
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$content = $authenticated ? gs_read_content() : null;
$blankExample = ['id' => '', 'kicker' => 'Concept de démonstration', 'title' => '', 'description' => '', 'tags' => [], 'image' => 'assets/logo-gamaservice.webp', 'alt' => '', 'published' => true];
$blankReview = ['id' => '', 'name' => '', 'project' => '', 'quote' => '', 'rating' => 5, 'published' => true];
?>
<!doctype html>
<html lang="fr">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex,nofollow,noarchive" />
    <meta name="theme-color" content="#060912" />
    <link rel="icon" type="image/png" href="../assets/favicon.png" />
    <link rel="stylesheet" href="admin.css" />
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
          <?php if ($pendingToken !== ''): ?>
            <p>Confirmez l’ouverture de la session d’administration.</p>
            <form method="post"><input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="consume_token" /><input type="hidden" name="token" value="<?= admin_escape($pendingToken) ?>" /><button class="primary-button" type="submit">Confirmer la connexion</button></form>
          <?php else: ?>
            <p>Un lien de connexion valable 15 minutes sera envoyé à <strong><?= admin_escape(GS_ADMIN_EMAIL) ?></strong>.</p>
            <form method="post"><input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="request_link" /><button class="primary-button" type="submit">Envoyer le lien de connexion</button></form>
          <?php endif; ?>
        </section>
      <?php else: ?>
        <section class="dashboard-intro">
          <div><p class="admin-eyebrow">Contenu du site</p><h1>Tableau de bord</h1><p>Les modifications publiées sont visibles immédiatement sur le site.</p></div>
          <nav class="admin-tabs" aria-label="Sections"><a href="#contact">Contact</a><a href="#minecraft">Minecraft</a><a href="#avis">Avis</a></nav>
        </section>

        <section id="contact" class="admin-section">
          <div class="section-title"><div><p class="admin-eyebrow">Coordonnées</p><h2>Adresse de contact</h2></div></div>
          <form class="editor compact-editor" method="post"><input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="save_contact" /><label>E-mail public<input type="email" name="email" required maxlength="160" value="<?= admin_escape($content['contact']['email'] ?? '') ?>" /></label><button class="primary-button" type="submit">Enregistrer</button></form>
        </section>

        <section id="minecraft" class="admin-section">
          <div class="section-title"><div><p class="admin-eyebrow">Portfolio</p><h2>Exemples Minecraft</h2></div><span><?= count($content['examples']) ?> élément(s)</span></div>
          <div class="editor-list">
            <?php foreach (array_merge($content['examples'], [$blankExample]) as $example): ?>
              <?php $isNew = ($example['id'] ?? '') === ''; ?>
              <article class="editor">
                <div class="editor-heading"><h3><?= $isNew ? 'Ajouter un exemple' : admin_escape($example['title']) ?></h3><?php if (!$isNew): ?><img src="../<?= admin_escape(gs_image_path($example['image'] ?? '')) ?>" alt="" width="140" height="90" /><?php endif; ?></div>
                <form method="post" enctype="multipart/form-data">
                  <input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="save_example" /><input type="hidden" name="id" value="<?= admin_escape($example['id'] ?? '') ?>" /><input type="hidden" name="current_image" value="<?= admin_escape($example['image'] ?? '') ?>" />
                  <div class="field-grid"><label>Titre<input name="title" required maxlength="100" value="<?= admin_escape($example['title'] ?? '') ?>" /></label><label>Libellé<input name="kicker" maxlength="60" value="<?= admin_escape($example['kicker'] ?? '') ?>" /></label></div>
                  <label>Description<textarea name="description" maxlength="500" required><?= admin_escape($example['description'] ?? '') ?></textarea></label>
                  <div class="field-grid"><label>Tags séparés par des virgules<input name="tags" maxlength="220" value="<?= admin_escape(implode(', ', $example['tags'] ?? [])) ?>" /></label><label>Texte alternatif de l’image<input name="alt" required maxlength="180" value="<?= admin_escape($example['alt'] ?? '') ?>" /></label></div>
                  <label>Nouvelle image (JPG, PNG ou WebP, 5 Mo max.)<input type="file" name="image_upload" accept="image/jpeg,image/png,image/webp" /></label>
                  <label class="check"><input type="checkbox" name="published" <?= !empty($example['published']) ? 'checked' : '' ?> /> Publié sur le site</label>
                  <button class="primary-button" type="submit"><?= $isNew ? 'Ajouter' : 'Enregistrer' ?></button>
                </form>
                <?php if (!$isNew): ?><form class="delete-form" method="post"><input type="hidden" name="csrf" value="<?= admin_escape(admin_csrf()) ?>" /><input type="hidden" name="action" value="delete_example" /><input type="hidden" name="id" value="<?= admin_escape($example['id']) ?>" /><button class="danger-button" type="submit">Supprimer</button></form><?php endif; ?>
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
      <?php endif; ?>
    </main>
  </body>
</html>
