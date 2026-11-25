<?php
/**
 * Angeleitete Installationsroutine.
 *
 * Eigenständiger Einstiegspunkt, analog zu index.php/admin.php/api.php/
 * mcp.php — nicht in admin.php integriert. Läuft VOR jeder Konfiguration: beim ersten Aufruf gibt es
 * noch kein config/config.php, das andere Einstiegspunkte hart voraussetzen.
 *
 * Ablauf:
 *   1. Self-Lock          — existiert config/config.php bereits? (5.1)
 *   2. Voraussetzungsprüfung — PHP-Version, Schreibrechte (5.5)
 *   3. Formular-Anzeige/-Verarbeitung — GET zeigt das Formular, POST
 *      validiert und schreibt config/config.php mit Double-Checked-Locking
 *      gegen parallele Requests (5.2–5.3, 6.4)
 *
 * config/users.json (cms_create_initial_admin()) wird im selben
 * Installationsdurchgang direkt nach config/config.php angelegt — Login
 * unter admin.php funktioniert damit sofort. Der Abschluss-Screen (Spec
 * 5.6: auffälliger „install.php jetzt löschen"-Hinweis + Login-Link,
 * render_install_complete()) ist fertig.
 */

// Markiert diesen Einstiegspunkt, damit config/config.php geladen werden darf
// (hier: damit lib/security.php und lib/config.php sich nicht als Forbidden
// ausgeben — config/config.php selbst existiert zu diesem Zeitpunkt gerade
// noch nicht).
define('CMS_ENTRY', true);

require_once __DIR__ . '/lib/security.php';

// Keine PHP-Stacktraces oder Pfade vor der Installation preisgeben.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Session mit HttpOnly/Secure/SameSite=Lax — für das CSRF-Token, obwohl noch
// kein Login existiert.
cms_start_session();

define('CONFIG_FILE', __DIR__ . '/config/config.php');

// save_config() — geteilt mit admin.php, Spec Abschnitt 8 Punkt 3.
require_once __DIR__ . '/lib/config.php';

// Rollen- und Status-Enums — lib/users.php nutzt sie beim Anlegen des Admins.
require_once __DIR__ . '/lib/enums.php';

// cms_create_initial_admin() — legt den ersten Admin-Account in
// config/users.json an, direkt im Anschluss an config/config.php.
require_once __DIR__ . '/lib/users.php';

// Site-URL ohne Konfiguration ermitteln — config/config.php mit site_domain
// existiert per Definition noch nicht (Self-Lock unten). Gleicher Auto-
// Erkennungs-Fallback wie in admin.php/index.php, wenn site_domain leer ist.
$proto = cms_is_https() ? 'https' : 'http';
$host  = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
define('SITE_URL', $proto . '://' . $host);

cms_send_security_headers(SITE_URL, true);

// ─── CSRF ────────────────────────────────────────────────────────────────────
// Identisches Muster wie admin.php (dort seit Projektbeginn lokal definiert,
// nicht in lib/ ausgelagert) — install.php braucht sein eigenes Formular-
// Token, bevor irgendein Nutzer-Account existiert.

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(): bool {
    return isset($_POST['csrf_token']) && hash_equals(csrf_token(), $_POST['csrf_token']);
}

// ─── 1. Self-Lock ────────────────────────────────────────────────
// Erste Zeile der eigentlichen Logik, keine Ausnahme, kein Admin-Override —
// wer neu installieren will, löscht config/config.php per FTP selbst. Kein unlink(__FILE__): auf manchem Shared-Hosting unzuverlässig
// (Dateirechte) und würde einen stillen Fehlschlag riskieren, der wie ein
// Erfolg aussieht.
if (is_file(CONFIG_FILE)) {
    render_already_installed();
    exit;
}

// ─── 2. Voraussetzungsprüfung ───────────────────────────────────
$requirement_errors = check_requirements();
if ($requirement_errors) {
    render_requirements_error($requirement_errors);
    exit;
}

// ─── 3. Formular-Anzeige & -Verarbeitung ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_install_submit();
} else {
    render_install_form();
}

// ─── Funktionen ─────────────────────────────────────────────────────────────

/**
 * Prüft PHP-Version und Schreibrechte auf die drei Verzeichnisse, die das
 * CMS zur Laufzeit ohnehin beschreibt. Leeres Array = alles okay.
 *
 * @return string[] Verständliche Fehlermeldungen, eine pro Problem.
 */
function check_requirements(): array {
    $errors = [];

    if (!version_compare(PHP_VERSION, '8.1.0', '>=')) {
        $errors[] = 'PHP-Version ' . PHP_VERSION . ' ist zu alt. Mindestens PHP 8.1 wird benötigt.';
    }

    $writable_dirs = [
        'config/'        => __DIR__ . '/config',
        'content/'       => __DIR__ . '/content',
        'assets/images/' => __DIR__ . '/assets/images',
    ];
    foreach ($writable_dirs as $label => $dir) {
        if (!is_dir($dir) || !is_writable($dir)) {
            $errors[] = "Verzeichnis „{$label}\" ist nicht beschreibbar. Bitte Schreibrechte per FTP setzen.";
        }
    }

    return $errors;
}

/** Hinweisseite, wenn config/config.php bereits existiert. */
function render_already_installed(): void {
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Bereits installiert</title>
    <style nonce="<?php echo e(cms_csp_nonce()); ?>"><?php echo get_install_css(); ?></style>
</head>
<body class="install-page">
    <div class="install-box">
        <h1>Bereits installiert</h1>
        <div class="msg success">Diese Instanz ist bereits eingerichtet.</div>
        <p>Eine erneute Installation über <code>install.php</code> ist gesperrt, solange <code>config/config.php</code> existiert. Wer neu installieren möchte, löscht die Datei per FTP.</p>
        <p class="install-footer"><a href="admin.php">Zum Login</a></p>
    </div>
</body>
</html>
<?php
}

/**
 * Fehlerseite der Voraussetzungsprüfung. Zeigt jeden
 * gefundenen Punkt einzeln, kein Formular, keine PHP-Interna.
 *
 * @param string[] $errors
 */
function render_requirements_error(array $errors): void {
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Installation nicht möglich</title>
    <style nonce="<?php echo e(cms_csp_nonce()); ?>"><?php echo get_install_css(); ?></style>
</head>
<body class="install-page">
    <div class="install-box">
        <h1>Installation nicht möglich</h1>
        <div class="msg error">Die Voraussetzungsprüfung hat folgende Probleme gefunden:</div>
        <ul class="req-list">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
        <p>Nach der Behebung diese Seite neu laden.</p>
    </div>
</body>
</html>
<?php
}

/**
 * Minimal-Formular: Site-Titel, Domain (optional), Admin-E-Mail, Passwort ×2.
 * Bei einem Validierungsfehler (Aufruf aus
 * handle_install_submit()) wird die Fehlermeldung gezeigt und die bereits
 * eingegebenen, unkritischen Felder bleiben ausgefüllt — Passwörter werden
 * nie zurückgeschrieben (gleiche Zurückhaltung wie bei anderen
 * Passwortformularen im Projekt).
 *
 * @param array{site_title?: string, site_domain?: string, admin_email?: string} $old
 */
function render_install_form(string $error = '', array $old = []): void {
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Installation</title>
    <style nonce="<?php echo e(cms_csp_nonce()); ?>"><?php echo get_install_css(); ?></style>
</head>
<body class="install-page">
    <form class="install-box" method="post">
        <h1>ClaudyCMS einrichten</h1>
        <?php if ($error !== ''): ?>
            <div class="msg error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?= csrf_field() ?>
        <label for="site_title">Site-Titel</label>
        <input type="text" id="site_title" name="site_title" value="<?= htmlspecialchars($old['site_title'] ?? '') ?>" required autofocus>

        <label for="site_domain">Domain <span class="hint">(optional, leer = automatisch)</span></label>
        <input type="url" id="site_domain" name="site_domain" value="<?= htmlspecialchars($old['site_domain'] ?? '') ?>" placeholder="https://www.example.com">

        <label for="admin_email">Admin-E-Mail</label>
        <input type="email" id="admin_email" name="admin_email" value="<?= htmlspecialchars($old['admin_email'] ?? '') ?>" required>

        <label for="admin_password">Admin-Passwort <span class="hint">(mindestens 12 Zeichen)</span></label>
        <input type="password" id="admin_password" name="admin_password" minlength="12" required>

        <label for="admin_password_confirm">Passwort bestätigen</label>
        <input type="password" id="admin_password_confirm" name="admin_password_confirm" minlength="12" required>

        <button type="submit">Installieren</button>
    </form>
</body>
</html>
<?php
}

/**
 * Verarbeitet den POST des Installationsformulars.
 * Validiert der Reihe nach (ein Fehler = sofortiger Abbruch mit erneuter
 * Formularanzeige, gleiches Muster wie $settings_error in admin.php), baut
 * bei Erfolg die Konfiguration aus config.example.php und schreibt sie über
 * den Double-Checked-Locking-Weg (cms_install_write_config()).
 */
function handle_install_submit(): void {
    $old = [
        'site_title'  => trim($_POST['site_title'] ?? ''),
        'site_domain' => rtrim(trim($_POST['site_domain'] ?? ''), '/'),
        'admin_email' => trim($_POST['admin_email'] ?? ''),
    ];
    $password  = (string)($_POST['admin_password'] ?? '');
    $password2 = (string)($_POST['admin_password_confirm'] ?? '');

    $error = '';
    if (!verify_csrf()) {
        $error = 'Ungültiges Sicherheits-Token. Bitte lade die Seite neu.';
    } elseif ($old['site_title'] === '') {
        $error = 'Bitte einen Site-Titel angeben.';
    } elseif ($old['site_domain'] !== '' && !preg_match('/^https?:\/\/[a-zA-Z0-9.\-]+(:\d+)?$/', $old['site_domain'])) {
        // Gleiche Regel wie das bestehende Domain-Feld in admin.php
        // (Einstellungen → Website) — ein Format, keine zweite Definition.
        $error = 'Ungültige Domain. Erlaubtes Format: https://www.example.com';
    } elseif ($old['admin_email'] === '' || !filter_var($old['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Ungültige E-Mail-Adresse.';
    } elseif (strlen($password) < 12) {
        // Gleicher Text wie das bestehende Passwort-ändern-Formular in
        // admin.php.
        $error = 'Das Admin-Passwort muss mindestens 12 Zeichen lang sein.';
    } elseif ($password !== $password2) {
        $error = 'Passwort und Bestätigung stimmen nicht überein.';
    }

    if ($error !== '') {
        render_install_form($error, $old);
        return;
    }

    // Basis config.example.php: alle übrigen Felder bleiben bei
    // ihren Beispiel-Defaults, nur site_title/site_domain werden überschrieben.
    // admin_password_hash bleibt bewusst der Platzhalter aus der Vorlage —
    // das echte Passwort landet ausschließlich gehasht in
    // config/users.json (cms_create_initial_admin() unten), nicht doppelt
    // auch hier.
    $config = require __DIR__ . '/config/config.example.php';
    $config['site_title']  = $old['site_title'];
    $config['site_domain'] = $old['site_domain'];

    switch (cms_install_write_config($config)) {
        case 'collision':
            // Ein paralleler zweiter Request war schneller — für
            // diesen Request ist die Lage identisch zum Self-Lock oben.
            render_already_installed();
            break;
        case 'error':
            $error = 'Fehler beim Speichern. Prüfe die Schreibrechte auf config/config.php.';
            render_install_form($error, $old);
            break;
        default: // 'ok'
            // config/config.php steht bereits — der Self-Lock oben greift ab
            // jetzt für jeden weiteren Aufruf, unabhängig vom Ausgang hier.
            // Admin-Anlage deshalb im
            // selben Durchgang, nicht als separater, wiederholbarer Schritt.
            $admin = cms_create_initial_admin($old['admin_email'], $password);
            if (!empty($admin['ok'])) {
                render_install_complete();
            } else {
                render_admin_creation_failed((string)($admin['error'] ?? 'Unbekannter Fehler.'));
            }
    }
}

/**
 * Spec-gerechter Abschluss-Screen: config/config.php UND
 * config/users.json stehen, Login funktioniert bereits. Ersetzt den bisher
 * gezeigten einfachen Zwischenstand (render_config_saved_placeholder()).
 *
 * Der Lösch-Hinweis ist bewusst auffällig gestaltet (eigene .msg.warning-
 * Klasse, nicht die schlichte .msg.success von oben) und erklärt kurz die
 * Sicherheitsrelevanz, nicht nur eine Zeile.
 */
function render_install_complete(): void {
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Installation abgeschlossen</title>
    <style nonce="<?php echo e(cms_csp_nonce()); ?>"><?php echo get_install_css(); ?></style>
</head>
<body class="install-page">
    <div class="install-box">
        <h1>Installation abgeschlossen</h1>
        <div class="msg success">Die Installation war erfolgreich: <code>config/config.php</code> und dein Admin-Account wurden angelegt.</div>
        <div class="msg warning">
            <strong>Wichtig: Lösche jetzt <code>install.php</code> per FTP.</strong>
            <p>Solange die Datei auf dem Server liegt, bleibt sie öffentlich erreichbar — auch wenn eine erneute Installation technisch gesperrt ist. Erst das Löschen entfernt den Endpunkt vollständig.</p>
        </div>
        <p class="install-footer"><a href="admin.php">Zum Login</a></p>
    </div>
</body>
</html>
<?php
}

/**
 * Fehlerseite, falls config/config.php zwar geschrieben wurde, aber
 * cms_create_initial_admin() danach scheitert (z. B. ein Locking-Fehler auf
 * config/users.json) — seltener Rand, in der Spec nicht einzeln behandelt.
 * Der Self-Lock aus Schritt 1 greift ab jetzt unwiderruflich, ein erneuter install.php-Aufruf würde nur „Bereits
 * installiert" zeigen. Einziger Ausweg ist der in der Spec ohnehin
 * vorgesehene Reset-Weg: config/config.php per FTP löschen und neu starten.
 */
function render_admin_creation_failed(string $message): void {
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin-Konto konnte nicht angelegt werden</title>
    <style nonce="<?php echo e(cms_csp_nonce()); ?>"><?php echo get_install_css(); ?></style>
</head>
<body class="install-page">
    <div class="install-box">
        <h1>Admin-Konto konnte nicht angelegt werden</h1>
        <div class="msg error">config/config.php wurde gespeichert, das Admin-Konto aber nicht: <?= htmlspecialchars($message) ?></div>
        <p>Bitte Schreibrechte auf <code>config/</code> per FTP prüfen. Zum erneuten Versuch <code>config/config.php</code> löschen und diese Seite neu laden.</p>
    </div>
</body>
</html>
<?php
}

/**
 * Eigenes, kleines CSS statt admin.php::get_admin_css() zu importieren —
 * install.php läuft vor jeder Konfiguration und soll ohne Abhängigkeit von
 * admin.php funktionieren. Gleiche Farben/Formensprache wie dessen
 * .login-page/.login-form (Wiedererkennung), bewusst nicht dieselbe Funktion.
 */
function get_install_css(): string {
    return <<<'CSS'
* { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --bg: #f8f9fa; --white: #ffffff; --text: #1a1a1a; --text-light: #666;
    --border: #dee2e6; --primary: #1a1a1a; --primary-hover: #333;
    --font: 'Segoe UI', system-ui, -apple-system, sans-serif;
}
body { font-family: var(--font); background: var(--bg); color: var(--text); line-height: 1.6; }

.install-page { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 2rem 1rem; }
.install-box { background: var(--white); padding: 2.5rem; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); width: 100%; max-width: 420px; }
.install-box h1 { font-size: 1.4rem; margin-bottom: 1.5rem; font-weight: 700; }
.install-box p { margin-bottom: 1rem; font-size: 0.9rem; }
.install-box label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem; }
.install-box .hint { font-weight: 400; color: var(--text-light); }
.install-box input { width: 100%; padding: 0.7rem 1rem; border: 1px solid var(--border); border-radius: 4px; font-size: 1rem; margin-bottom: 1rem; }
.install-box button { width: 100%; padding: 0.7rem; background: var(--primary); color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; font-weight: 600; }
.install-box button:hover { background: var(--primary-hover); }
.install-footer { text-align: center; font-size: 0.85rem; }
.install-footer a { color: var(--text-light); }

.msg { padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.9rem; }
.msg.success { background: #d1e7dd; color: #0f5132; }
.msg.error { background: #f8d7da; color: #842029; }
.msg.warning { background: #fff3cd; color: #664d03; border: 2px solid #ffca2c; }
.msg.warning strong { display: block; margin-bottom: 0.4rem; }
.msg.warning p { margin: 0; font-size: 0.85rem; }

.req-list { margin: 0 0 1rem 1.2rem; font-size: 0.9rem; }
.req-list li { margin-bottom: 0.4rem; }
CSS;
}
